<?php

namespace App\Http\Controllers;

use App\Enums\PublishState;
use App\Enums\RequestDecision;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Exceptions\SchedulingException;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Store;
use App\Models\TcpEmployeeJobCode;
use App\Models\TcpJobCodeRole;
use App\Models\User;
use App\Models\WorkSegment;
use App\Services\Scheduling\BoardService;
use App\Services\Scheduling\EmployeeRequestService;
use App\Services\Scheduling\HourlyHeadcountCounter;
use App\Services\Scheduling\HourlySalesReader;
use App\Services\Scheduling\LaborCostEstimator;
use App\Services\Scheduling\LiveSegmentFeed;
use App\Services\Scheduling\SchedulePublisher;
use App\Services\Scheduling\ShiftRangeService;
use App\Services\Scheduling\ShiftService;
use App\Services\Scheduling\StoreSettingService;
use App\Services\Scheduling\TcpEmployeeJobCodeReader;
use App\Services\Scheduling\TcpEmployeeReader;
use App\Services\Scheduling\WorkSegmentService;
use App\Services\Scheduling\WorkSegmentSyncService;
use App\Support\ActingUser;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The web console. Deliberately thin: every button routes to the same service
 * the API calls, so clicking around here exercises the real domain code rather
 * than a parallel implementation of it.
 *
 * Server-rendered and POST-then-redirect throughout. No build step, no client
 * state to drift out of sync with the database — the page you are looking at is
 * a query result, always.
 */
class BoardController extends Controller
{
    /** The store the roster was last read from TCP for. See pullEmployeesOnStoreChange(). */
    private const EMPLOYEE_PULL_KEY = 'tcp_employee_pull_store';

    public function __construct(
        private readonly BoardService $board,
        private readonly ShiftService $shifts,
        private readonly WorkSegmentService $segments,
        private readonly EmployeeRequestService $requests,
        private readonly LaborCostEstimator $costs,
        private readonly SchedulePublisher $publisher,
        private readonly WorkSegmentSyncService $segmentSync,
        private readonly TcpEmployeeReader $tcpEmployees,
        private readonly BusinessDay $businessDay,
        private readonly ActingUser $actingUser,
        private readonly StoreSettingService $settings,
        private readonly HourlySalesReader $sales,
        private readonly HourlyHeadcountCounter $heads,
        private readonly LiveSegmentFeed $live,
        private readonly TcpEmployeeJobCodeReader $tcpJobCodes,
        private readonly ShiftRangeService $range,
    ) {}

    public function index(Request $request): View
    {
        $stores = Store::query()->orderBy('id')->get();
        $storeId = (int) ($request->query('store') ?: $stores->first()?->id ?: DemoSeeder::STORE_ID);

        $this->pullEmployeesOnStoreChange($request, $storeId);

        $date = (string) ($request->query('date')
            ?: $this->businessDay->toLocal($storeId, now())->toDateString());

        // Before the queries below, for the same reason the week view does it:
        // the day board is where punches are approved and corrected, and it
        // cannot show hours it never asked for. One day, one request, once.
        $this->pullSegmentsOnRangeChange($request, $storeId, $date, $date);

        $board = $this->board->forDate($storeId, $date);

        $shifts = Shift::query()
            ->with(['employee', 'position'])
            ->forBoard($storeId, $date)
            ->get();

        $segments = WorkSegment::query()
            ->with(['employee', 'shift'])
            ->forBoard($storeId, $date)
            ->get();

        // One conflicts() call per shift. Cheap at a store-day's volume, and it
        // is the whole point of the screen: the warnings must be visible at the
        // moment you look at the shift, not behind another click.
        $conflicts = $shifts->mapWithKeys(
            fn (Shift $shift): array => [$shift->id => $this->shifts->conflicts($shift)]
        );

        return view('board.index', [
            'stores' => $stores,
            'storeId' => $storeId,
            'date' => $date,
            'board' => $board,
            'shifts' => $shifts,
            'segments' => $segments,
            'conflicts' => $conflicts,
            // The status pill's opening reading. Taken from our own table with
            // no vendor call, so the page paints at once; the poll that starts
            // half a second later is what makes it current.
            'live' => $this->live->snapshot($storeId, $date, $date),
            // What the two range buttons would touch. Counted here so the labels
            // can name the number — which is the only thing standing between a
            // manager and a day they did not mean to clear.
            'range' => $this->range->summary($storeId, $date, $date),
            // What TCP will file each person's hours as. The forms print it
            // beside the name, now that nobody picks it.
            //
            // ON THIS DATE, because hiring's answer is effective-dated: a shift
            // read after a promotion carries the new role, and the option has to
            // say the same thing the save will store.
            'jobCodes' => $this->jobCodesForForms($storeId, $date),
            'positions' => Position::query()->orderBy('id')->get(),
            // The same rule the week view follows: dropdowns offer only roles
            // TCP has a job code for, and the strict list rides along so the
            // forms can say when a store has none. See the week() payload.
            'offerablePositionIds' => TcpJobCodeRole::positionIdsOfferableAt(
                $stores->firstWhere('id', $storeId)?->store_number,
            ),
            'pushablePositionIds' => TcpJobCodeRole::positionIdsPushableAt(
                $stores->firstWhere('id', $storeId)?->store_number,
            ),
            'roster' => $this->roster($storeId, $date),
            // What the publish button is about to send, so the count is on the
            // button rather than a surprise after pressing it.
            'publishable' => ($pending = $this->publisher->pendingInRange($storeId, $date, $date))->count(),
            // How many of those Humanity is ALREADY holding, which is what makes
            // the button say "Republish" instead of "Publish". A manager who has
            // just unpublished something wants to see that word.
            'republishable' => $pending->whereNotNull('humanity_shift_id')->count(),
            'requests' => EmployeeRequest::query()
                ->with(['employee', 'decisions'])
                ->where('store_id', $storeId)
                ->orderByDesc('id')
                ->get(),
            'timezone' => $board['timezone'],
        ]);
    }

    /**
     * Ask TCP for this store's roster the first time the board lands on it.
     *
     * ON CHANGE, NOT ON RENDER. The board re-renders on every date step and
     * after every POST-then-redirect, and a vendor round trip on each of those
     * would put TCP's latency in front of the main screen and burn the retry
     * budget on a client whose timeout is 30 seconds. The last store pulled is
     * kept in the session, so switching stores costs one call and paging
     * through a fortnight costs none.
     *
     * NOTHING HERE CAN BREAK THE BOARD. The pull is a convenience; the schedule
     * is not. Any failure degrades to a message and the page renders exactly as
     * it would have — which is why the whole thing is wrapped, rather than
     * trusting the client to only ever throw IntegrationException.
     *
     * It only ever writes integration_identities. See TcpEmployeeReader: an
     * employee TCP knows about and we do not is reported, never created, because
     * `employees` is a projection and an invented row there is erased by the
     * next replay.
     */
    private function pullEmployeesOnStoreChange(Request $request, int $storeId): void
    {
        if (! $request->hasSession() || (int) $request->session()->get(self::EMPLOYEE_PULL_KEY) === $storeId) {
            return;
        }

        // Recorded BEFORE the call, not after: a store whose pull fails must not
        // retry on every subsequent render of the same board.
        $request->session()->put(self::EMPLOYEE_PULL_KEY, $storeId);

        try {
            $report = $this->tcpEmployees->forStore($storeId);
        } catch (Throwable $e) {
            $this->flashNow('err', 'Could not read the roster from TCP — '.class_basename($e).': '.$e->getMessage());

            return;
        }

        // IMMEDIATELY AFTER THE ROSTER, and only when it succeeded: the job code
        // pull is driven by the TCP ids the roster pull has just confirmed, so
        // running it first would ask about people it had not mapped yet.
        //
        // This is what removed the position dropdown. Nobody presses anything
        // for it, on the same principle as the punch heartbeat: if TCP knows the
        // answer, the console should not be asking a manager for it.
        $this->pullEmployeeJobCodes($storeId);

        // Not configured, or not mapped: both are ordinary states in a service
        // that is still being wired up, and neither is worth shouting about on
        // a screen somebody opened to look at a schedule.
        $reasons = array_column($report['skipped'], 'reason');

        if (in_array('tcp_not_configured', $reasons, true)) {
            return;
        }

        // The STORE NUMBER, not the numeric TCP location id: that is what
        // TcpEmployeeReader filters GET /employees by, and what it reports
        // missing. This checked for the wrong reason string and so never fired,
        // leaving a store with no number to report a cheerful "0 at this
        // location" instead of saying why.
        if (in_array('store_has_no_store_number', $reasons, true)) {
            $this->flashNow('err', 'This store has no store number, so its roster could not be read from TCP.');

            return;
        }

        $unmatched = $report['unmatched'];

        $message = 'TCP roster: '.$report['fetched'].' at this location, '
            .$report['mapped'].' newly linked, '.$report['already_mapped'].' already linked.';

        if ($unmatched === []) {
            $this->flashNow('ok', $message);

            return;
        }

        // The interesting half. These people are on TCP's roster for this store
        // and are in no hiring event we have seen, so they cannot be scheduled
        // until hiring sends them — naming them is what makes that actionable.
        $names = collect($unmatched)->take(5)->pluck('name')->join(', ');

        $this->flashNow('err', $message.' '.count($unmatched).' not in our roster'
            .' ('.$names.(count($unmatched) > 5 ? ', …' : '').')'
            .' — they arrive from hiring, and are not created from TCP.');
    }

    /**
     * Read each employee's TCP job code assignments.
     *
     * WHAT THIS REPLACED: a Position dropdown on every form. A punch needs a
     * jobCodeId and it used to be assembled from a picked position — see
     * TcpJobCodeRole::jobCodeIdFor(), which survives only for the open-shift
     * case that has no person in it. Assembling a code is guessing whether TCP
     * has it, and it frequently does not.
     *
     * SILENT WHEN IT WORKS. This runs on a store change, behind the roster pull
     * that just reported its own numbers; a second banner saying job codes also
     * loaded is noise on a screen somebody opened to read a schedule. The one
     * thing worth saying is the case that leaves a form unable to file hours,
     * and the form says that itself, per person, where it can be acted on.
     *
     * CANNOT BREAK THE PAGE, for the same reason nothing else on this render
     * path can: the mappings already stored are not a convenience, only their
     * freshness is.
     */
    private function pullEmployeeJobCodes(int $storeId): void
    {
        try {
            // The report is deliberately not read. People TCP assigns codes to
            // who are in no hiring event we have seen are the one interesting
            // case, and the roster pull above already reports that same fact
            // from the other side — saying it twice on one render helps nobody.
            // `scheduling:sync-employee-job-codes` prints the full report.
            $this->tcpJobCodes->syncStore($storeId);
        } catch (Throwable $e) {
            $this->flashNow('err', 'Could not read job codes from TCP — '
                .class_basename($e).': '.$e->getMessage());
        }
    }

    /**
     * The position for somebody's shift or punch, DERIVED rather than picked.
     *
     * This is the whole of what the removed dropdowns used to be asked for. TCP
     * assigns each person a job code per store — 37951001 is "Crew Member" at
     * store 10 — and the trailing two digits are already mapped to our
     * positions, so the answer a manager was typing in was one TCP could give.
     *
     * The board still STORES a position on every row: the cost estimator reads
     * it, the chips print it, and Humanity refuses a shift without one. So the
     * field went, the value did not.
     *
     * Null when TCP has no assignment for this person at this store. That is a
     * real state, not an error here — a punch refuses it loudly, because hours
     * with no code cannot be filed, while a plan tolerates it, because a plan is
     * ours until somebody publishes it.
     */
    private function derivedPositionId(int $storeId, ?int $employeeId): ?int
    {
        if ($employeeId === null) {
            return null;
        }

        return TcpEmployeeJobCode::positionIdFor($employeeId, $this->storeNumberFor($storeId));
    }

    /**
     * The position for a PLANNED shift, read off the person rather than a form.
     *
     * TWO SOURCES, BOTH THE PERSON'S, in a fixed order:
     *
     *   1. TCP's assignment at this store. Store-specific — 37951001 is Crew
     *      Member at store 10 and says nothing about store 42 — and the only
     *      answer both vendors can carry: it is by definition a code TCP has, and
     *      its role maps to a Humanity schedule, so a shift built from it can be
     *      published AND its hours filed.
     *   2. What HIRING says they are employed as, effective-dated so a shift
     *      after a promotion carries the new role. This is the system of record
     *      for the fact, and it answers for everybody TCP has no assignment for —
     *      which was the hole this fell through before.
     *
     * TCP FIRST DESPITE HIRING OWNING THE FACT, and the reason is narrow: hiring's
     * vocabulary is wider than either vendor's. Driver, Insider and Shift Lead are
     * real jobs that exist in no TCP job code and no Humanity schedule, so
     * preferring hiring would take a person TCP has already placed as Crew Member
     * and roster them as something that cannot publish. Where the two disagree,
     * TCP's is the answer that works; where TCP is silent, hiring's is the only
     * answer there is. Neither is a scheduling pick, which is the point.
     *
     * NULL STOPS HERE. It used to fall through to whatever position_id the request
     * carried, which by then came from a select the manager could not see — so
     * somebody neither system knew about was quietly rostered as whatever happened
     * to be first in the list, and their labour cost and their published schedule
     * both said so. A plan with no role is honest and visible: the option says the
     * profile is empty, the chip prints no role, and SchedulePublisher refuses it
     * by name with the two places to go and fix it.
     *
     * The one manual position left on the console is an OPEN slot's, which has no
     * person to read anything off.
     */
    private function plannedPositionId(int $storeId, ?int $employeeId, ?string $date = null): ?int
    {
        if ($employeeId === null) {
            return null;
        }

        return $this->derivedPositionId($storeId, $employeeId)
            ?? Employee::query()->find($employeeId)?->positionIdOn($date);
    }

    /**
     * Refuse a hand-entered punch for somebody TCP has no job code for here.
     *
     * Thrown as a VALIDATION error rather than an exception, so it lands back on
     * the form beside the field that caused it instead of on an error page — the
     * same shape guardPushablePosition() uses, and on employee_id because that is
     * the field somebody can actually change in response.
     *
     * A STORE TCP CANNOT NAME IS A DIFFERENT SITUATION and returns early. "TCP
     * has not assigned this person a code here" is something a manager can get
     * fixed; "this store is not in TCP" is not about the person at all, and
     * refusing every punch would stop a store recording worked hours to solve a
     * timeclock problem it does not have. The hours save and the chip says why
     * they cannot be pushed — see the same reasoning in guardPushablePosition().
     */
    private function guardEmployeeHasJobCode(int $storeId, int $employeeId): void
    {
        $storeNumber = $this->storeNumberFor($storeId);

        if (TcpJobCodeRole::storeKeyFor($storeNumber) === null) {
            return;
        }

        if (TcpEmployeeJobCode::roleFor($employeeId, $storeNumber) !== null) {
            return;
        }

        $who = Employee::query()->find($employeeId)?->fullName() ?? 'That employee';

        throw ValidationException::withMessages([
            'employee_id' => $who.' has no TCP job code at store '.($storeNumber ?? $storeId)
                .', so TCP cannot be told what role their hours were worked as. Assign them a job code at this '
                .'store in TCP — the board reads assignments hourly, and immediately when you switch stores and back.',
        ]);
    }

    /**
     * Each employee's ROLE, from their profile, for the forms to show.
     *
     * REMOVING A DROPDOWN MUST NOT REMOVE THE INFORMATION. A manager picking a
     * position at least knew what role the hours would be filed as; deleting the
     * field without putting that fact back would make the form quieter and less
     * honest at the same time. So every employee option carries their role, and
     * anyone with none says so on the option itself — before it is chosen, rather
     * than as an error after.
     *
     * BOTH SOURCES, SEPARATELY, because the two forms need different ones and
     * collapsing them would put a label on an option that the save then
     * contradicts. A PUNCH needs TCP's code — no code, no hours — so that form
     * reads `tcp` and disables anyone without it. A PLANNED shift needs only a
     * role, so it reads `tcp` then `hiring`, the same order plannedPositionId()
     * resolves in, and what the option says is what the shift will store.
     *
     * Two queries for the whole form, keyed by employee id.
     *
     * @return array<int,array{tcp: ?array{label: string, code: string}, hiring: ?array{label: string}}>
     */
    private function jobCodesForForms(int $storeId, ?string $date = null): array
    {
        $positions = Position::query()->pluck('label', 'id');
        $roles = [];

        // HIRING, the system of record for what somebody is employed as. Read
        // outside the store-key guard below because a store TCP cannot name
        // leaves this as the only source there is.
        foreach (Employee::query()
            ->forStore($storeId)
            ->schedulable()
            ->with('positions')
            ->get() as $employee) {
            $positionId = $employee->positionIdOn($date);

            if ($positionId === null) {
                continue;
            }

            $roles[(int) $employee->id]['hiring'] = [
                'label' => (string) ($positions[$positionId] ?? 'position #'.$positionId),
            ];
        }

        $storeKey = TcpJobCodeRole::storeKeyFor($this->storeNumberFor($storeId));

        if ($storeKey === null) {
            return $roles;
        }

        // TCP, the timeclock's own assignment, which is what a punch is filed
        // under whatever hiring believes.
        foreach (TcpEmployeeJobCode::query()
            ->where('is_role', true)
            ->where('store_key', $storeKey)
            ->get() as $row) {
            $positionId = $row->positionId();

            $roles[(int) $row->employee_id]['tcp'] = [
                // OUR label when the suffix is mapped, TCP's own when it is not —
                // a code we cannot translate is still a code, and printing the raw
                // description beats printing nothing.
                'label' => $positionId === null
                    ? (string) ($row->description ?? $row->job_code_id)
                    : (string) ($positions[$positionId] ?? $row->description ?? $row->job_code_id),
                'code' => (string) $row->job_code_id,
            ];
        }

        return $roles;
    }

    /** The number on the building, which is what a job code is built from. */
    private function storeNumberFor(int $storeId): ?string
    {
        return Store::query()->whereKey($storeId)->value('store_number');
    }

    /**
     * Read the visible range from TCP as part of rendering it.
     *
     * WHY THIS STILL EXISTS NOW THAT THERE IS A HEARTBEAT. The heartbeat (see
     * LiveSegmentFeed and board/_live.blade.php) is what keeps an open board
     * current from one second to the next. This is about the FIRST paint: a
     * grid that renders empty and fills in a second later is a grid somebody
     * can read the wrong answer off, and "nobody worked" is exactly the wrong
     * answer to show while the truth is still in flight.
     *
     * ONE SET OF BOOKS WITH THE HEARTBEAT. This used to key off a session value
     * of its own, which meant a navigation pulled the range and the first poll
     * half a second later pulled the very same range again — it had never heard
     * of the session key. Both now go through LiveSegmentFeed::refresh(), which
     * holds one shared record of when each range was last read. Two consequences
     * worth having: no duplicate call per navigation, and freshness shared
     * between PEOPLE — the second manager to open Tuesday does not pay for a
     * round trip the first one already made.
     *
     * NOTHING HERE CAN BREAK THE PAGE. The pull is a convenience; the hours
     * already in the table are not. Any failure degrades to a message and the
     * grid renders exactly as it would have — and the heartbeat will keep
     * retrying it on its own interval afterwards.
     */
    private function pullSegmentsOnRangeChange(Request $request, int $storeId, string $from, string $to): void
    {
        try {
            $result = $this->live->refresh($storeId, $from, $to);
        } catch (Throwable $e) {
            // refresh() swallows vendor failures into the range's state so the
            // heartbeat can report them, so reaching here means something more
            // unusual. Still not fatal: the grid is a query result either way.
            $this->flashNow('err', "Could not read this week's hours from TCP — "
                .class_basename($e).': '.$e->getMessage());

            return;
        }

        // null is "the range was already fresh, or somebody else is fetching it
        // right now". Neither is news.
        if ($result === null) {
            return;
        }

        // Rows TCP sent that we refused. Not silence: they are hours that exist
        // at the vendor and are not on this screen.
        $skipped = $result['skipped'] ?? [];

        if ($skipped !== []) {
            $why = collect($skipped)
                ->map(fn ($row) => is_array($row) ? ($row['reason'] ?? 'unknown') : (string) $row)
                ->countBy()
                ->map(fn (int $n, string $reason) => "{$reason} ×{$n}")
                ->join(', ');

            $this->flashNow('err', 'Pulled '.$result['fetched'].' from TCP. Skipped: '.$why.'.');

            return;
        }

        // Silent when there was nothing to say. Somebody who opened this tab to
        // read a grid does not need a banner telling them the grid loaded.
        if ((int) ($result['created'] ?? 0) === 0 && (int) ($result['updated'] ?? 0) === 0) {
            return;
        }

        $parts = [];
        foreach (['created', 'updated'] as $word) {
            if ((int) ($result[$word] ?? 0) > 0) {
                $parts[] = $result[$word].' '.$word;
            }
        }

        $this->flashNow('ok', 'TCP: '.implode(', ', $parts).' for this week.');
    }

    /**
     * Flash for THIS render, not the next one.
     *
     * index() is reached by a GET, so there is no redirect to carry an ordinary
     * flash. now() also has to yield to anything a redirect already put there:
     * the result of the action somebody just took matters more than a roster
     * summary they did not ask for.
     */
    private function flashNow(string $key, string $message): void
    {
        if (session()->has('ok') || session()->has('err')) {
            return;
        }

        session()->now($key, $message);
    }

    /**
     * Switch who the console is acting as.
     *
     * NOT a login. It writes one id to the session so changes can be
     * attributed; it grants and denies nothing. See App\Support\ActingUser.
     */
    public function setActingUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $this->actingUser->set(($data['user_id'] ?? null) === null ? null : (int) $data['user_id']);

        return back()->with('ok', $this->actingUser->id() === null
            ? 'Acting as nobody. Changes will be recorded as unattributed.'
            : 'Now acting as '.$this->actingUser->name().'.');
    }

    /**
     * The week grid: seven days across, one row per employee, in one of two
     * views.
     *
     *   PLANNED — the shifts we intend, dragged into place and published to
     *   Humanity. This is the schedule being built.
     *
     *   ACTUAL — the punches TCP recorded against those days. Approve, correct
     *   or delete them here; an open punch shows its clock-in with no clock-out,
     *   because somebody is still in the store and there are no hours yet.
     *
     * ONE PAGE, TWO READINGS OF THE SAME WEEK. They are separate views rather
     * than two chips in one cell because they answer different questions and are
     * acted on by different hands — and because a cell holding both is unreadable
     * the moment anybody works a split shift.
     *
     * Both sides are loaded on either view. The counts on the inactive tab are
     * the reason: a manager who cannot see "6 to approve" without switching will
     * not switch, and the hours sit unapproved.
     */
    public function week(Request $request): View
    {
        $stores = Store::query()->orderBy('id')->get();
        $storeId = (int) ($request->query('store') ?: $stores->first()?->id ?: DemoSeeder::STORE_ID);

        $anchor = (string) ($request->query('week')
            ?: $this->businessDay->toLocal($storeId, now())->toDateString());

        /**
         * Three readings of the same seven days, and BOTH is the default.
         *
         * They were two tabs because they answer different questions — "who is
         * working Thursday" and "did Thursday get worked" — and because one cell
         * holding both gets crowded the moment somebody works a split shift.
         * That crowding is real and the stacked cell below is built around it:
         * plan on top, worked underneath, a rule between them.
         *
         * What the split cost was the comparison. Plan and actual side by side
         * in one cell is the only way to see that Thursday was staffed for four
         * and worked by three, which is the question a week grid is actually
         * read for. The single-purpose tabs stay for the two jobs that want the
         * room: dragging out a plan, and signing off a week of hours.
         */
        $view = match ($request->query('view')) {
            'planned' => 'planned',
            'actual' => 'actual',
            default => 'both',
        };

        $showPlanned = $view !== 'actual';
        $showActual = $view !== 'planned';

        // TUESDAY-FIRST, and stated explicitly rather than left to startOfWeek()'s
        // locale default — which would be Sunday or Monday depending on a config
        // value that has nothing to do with how these stores run.
        //
        // This is also what makes the date box work as a week picker: ANY date
        // lands on the Tuesday of the week containing it, so picking a Thursday
        // shows Thursday's week rather than starting the grid on Thursday.
        $start = CarbonImmutable::parse($anchor)->startOfWeek(CarbonInterface::TUESDAY);
        $days = collect(range(0, 6))->map(fn (int $i): string => $start->addDays($i)->toDateString());

        // Store-local today, for telling "still in the store" from "never
        // clocked out". Both are a punch with no time_out; only the date says
        // which, and it has to be the STORE's date — a board read at 01:00 UTC
        // is still the previous evening in New York.
        $today = $this->businessDay->toLocal($storeId, now())->toDateString();

        $shifts = Shift::query()
            ->with(['employee', 'position'])
            // The chip shows whether a shift can be dragged, which depends on
            // whether punches are reconciled against it. Counted here so a
            // seven-day grid is one query, not one per chip.
            ->withCount('workSegments')
            ->forStoreBetween($storeId, $days->first(), $days->last())
            ->get();

        // BEFORE the query, or the first render of a week shows the punches it
        // had rather than the ones it just fetched.
        if ($showActual) {
            $this->pullSegmentsOnRangeChange($request, $storeId, $days->first(), $days->last());
        }

        $segments = WorkSegment::query()
            ->with(['employee', 'position'])
            ->forStoreBetween($storeId, $days->first(), $days->last())
            ->get();

        $roster = $this->roster($storeId, $days->first());

        /**
         * WHAT THE STORE TOOK, and HOW MANY PEOPLE WERE IN IT TO TAKE IT. The
         * two are one row on the grid and have to agree hour for hour, so the
         * window is read once — from the sales reader, which owns it — and
         * handed to the counter rather than looked up twice.
         *
         * The sales half can be unavailable; the headcount half never is. It is
         * counted from the shifts and punches already fetched above, so it costs
         * no query and cannot fail — which is why the hour row now survives the
         * warehouse being down instead of disappearing with it.
         */
        $sales = $this->sales->forRange($storeId, $days->first(), $days->last());
        $heads = $this->heads->forRange($storeId, $days->all(), $sales['hours'], $shifts, $segments);

        return view('board.week', [
            'stores' => $stores,
            'storeId' => $storeId,
            'weekStart' => $start->toDateString(),
            'days' => $days->all(),
            'today' => $today,
            'weeks' => $this->selectableWeeks($storeId, $start),
            'view' => $view,
            'showPlanned' => $showPlanned,
            'showActual' => $showActual,
            'shifts' => $shifts,
            'segments' => $segments,
            'byCell' => $shifts->groupBy([
                fn (Shift $s): string => (string) ($s->employee_id ?? 'open'),
                fn (Shift $s): string => $this->dateOf($s->business_date),
            ]),
            // Punches have no open row: a punch is somebody clocking in, so
            // there is always an employee behind it.
            'segsByCell' => $segments->groupBy([
                fn (WorkSegment $g): string => (string) $g->employee_id,
                fn (WorkSegment $g): string => $this->dateOf($g->business_date),
            ]),
            // The forms offer the roster. The GRID has to show more than that on
            // the actual side — see rowsForActual().
            'roster' => $roster,
            // Anyone who PUNCHED here is a row, even off the roster — otherwise
            // a cover shift from another store has hours nothing on the grid
            // accounts for. Only when the actual side is on screen; a pure
            // planning view has nothing to say about them.
            'rows' => $showActual ? $this->rowsForActual($roster, $segments, $days->first()) : $roster,
            'positions' => Position::query()->orderBy('id')->get(),
            /**
             * WHAT EVERY POSITION DROPDOWN ON THIS SCREEN OFFERS: only roles TCP
             * has a job code for.
             *
             * Per store where TCP knows the store, so Management stays at the
             * one store that carries it; the estate's roles where it does not,
             * so the demo store gets a usable form rather than an empty select.
             * See TcpJobCodeRole::positionIdsOfferableAt().
             *
             * THE PLANNED FORM IS FILTERED TOO, which it did not used to be. A
             * plan goes to Humanity and needs no job code, so offering the full
             * table was defensible in isolation — but Driver, Insider and Shift
             * Lead exist in no TCP code anywhere, and every shift rostered
             * against one is hours that cannot be filed when somebody works it.
             * Better to not offer the role than to discover it at payroll.
             */
            'offerablePositionIds' => TcpJobCodeRole::positionIdsOfferableAt(
                $stores->firstWhere('id', $storeId)?->store_number,
            ),
            /**
             * The strict per-store list, kept ALONGSIDE the offerable one rather
             * than folded into it, because [] is the one fact the dropdown
             * cannot express: this store is not in TCP, so nothing on the list
             * above can actually be filed from it. The forms say so out loud
             * instead of implying otherwise by having options at all.
             */
            'pushablePositionIds' => TcpJobCodeRole::positionIdsPushableAt(
                $stores->firstWhere('id', $storeId)?->store_number,
            ),
            'costs' => $this->costs->estimateFor($shifts, $storeId, null),
            'actuals' => $this->costs->actualFor($segments, $storeId),
            'publishable' => ($pending = $this->publisher
                ->pendingInRange($storeId, $days->first(), $days->last()))->count(),
            'republishable' => $pending->whereNotNull('humanity_shift_id')->count(),
            'timezone' => $this->businessDay->timezoneFor($storeId),
            // See the note in index(). Keyed on the whole week, which is the
            // range the poll will keep warm.
            'live' => $this->live->snapshot($storeId, $days->first(), $days->last()),
            // See the note in index(). Keyed on the whole week, which is exactly
            // what the buttons act on.
            'range' => $this->range->summary($storeId, $days->first(), $days->last()),
            // See the note in index(). One query, keyed by employee id, dated
            // to the first day on screen.
            'jobCodes' => $this->jobCodesForForms($storeId, $days->first()),
            /**
             * WHAT THE STORE WAS DOING WHILE THEY WORKED — read from
             * LC_PIZZA_DATA, never stored here. Two people on at 11:00 is right
             * or badly wrong depending on whether 11:00 is a $90 hour, and that
             * number used to live in a system nobody had open while building a
             * rota.
             *
             * It cannot fail the render: HourlySalesReader turns every failure
             * into available => false and the grid drops the figures. See its
             * docblock for why that is the normal case rather than the
             * exceptional one.
             */
            'sales' => $sales,
            /**
             * HOW MANY PEOPLE WERE IN THE STORE IN EACH OF THOSE HOURS — the
             * answer the sales figures on their own cannot give. $600 at 17:00
             * is right with four on the floor and a disaster with one, and
             * working out which meant counting chips down fourteen cells.
             *
             * Both sides of it, always, whichever tab is on screen: the planned
             * tab shows who should be here, the actual tab who clocked in, and
             * the combined tab both numbers against each other. See
             * HourlyHeadcountCounter.
             */
            'heads' => $heads,
        ]);
    }

    /**
     * The grid's rows on the ACTUAL side: the roster, plus anybody who punched
     * here this week and is not on it.
     *
     * THE ROSTER IS THE WRONG LIST FOR WORKED HOURS, and quietly so. It answers
     * "who can be scheduled here now" — schedulable status, assigned to this
     * store — while a punch is a record of something that already happened. The
     * two disagree in exactly the cases that matter most:
     *
     *   somebody terminated on Wednesday still worked Monday and Tuesday, and
     *   hiring's termination drops them off the roster retroactively;
     *
     *   somebody covering from another store punches in here without ever
     *   appearing on this store's roster.
     *
     * Rendering only the roster would drop those chips off the grid while their
     * hours kept counting in the header total and in the cost — a week that does
     * not add up, with the missing rows invisible rather than flagged. They are
     * appended instead, marked off_roster so the row can say why it is there.
     *
     * @param  array<int, array<string, mixed>>  $roster
     * @param  Collection<int, WorkSegment>  $segments
     * @return array<int, array<string, mixed>>
     */
    private function rowsForActual(array $roster, $segments, string $date): array
    {
        $rosterIds = array_map(static fn (array $row): int => (int) $row['model']->id, $roster);

        $strays = $segments
            ->map(static fn (WorkSegment $segment): ?Employee => $segment->employee)
            ->filter()
            ->unique('id')
            ->reject(fn (Employee $employee): bool => in_array((int) $employee->id, $rosterIds, true))
            ->map(function (Employee $employee) use ($date): array {
                $rate = $this->costs->rateOn((int) $employee->id, $date);

                return [
                    'model' => $employee,
                    'age' => null,
                    'rate' => $rate === null ? null : (float) $rate->base_pay + (float) $rate->performance_pay,
                    // Availability is a scheduling question. It has nothing to
                    // say about hours already worked.
                    'windows' => collect(),
                    'off_roster' => true,
                ];
            })
            ->values()
            ->all();

        return [...$roster, ...$strays];
    }

    /**
     * The weeks the picker offers: Tuesdays, and nothing else.
     *
     * A free date box let you land on a Wednesday and wonder why the grid still
     * began on Tuesday. Offering only week starts removes the question — every
     * option IS a week, so picking one and pressing Go can only mean one thing.
     *
     * Centred on the store's current week rather than on the one being viewed,
     * so the list does not crawl away from "now" as you page through it. The
     * week actually on screen is always included even when it falls outside the
     * window, or a deep link to last spring would render with nothing selected.
     *
     * @return array<int, array{value: string, label: string, current: bool}>
     */
    private function selectableWeeks(int $storeId, CarbonImmutable $viewing): array
    {
        $thisWeek = CarbonImmutable::parse($this->businessDay->toLocal($storeId, now())->toDateString())
            ->startOfWeek(CarbonInterface::TUESDAY);

        $starts = collect(range(-16, 8))
            ->map(fn (int $offset): CarbonImmutable => $thisWeek->addWeeks($offset))
            ->push($viewing)
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->sortBy(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();

        return $starts->map(fn (CarbonImmutable $date): array => [
            'value' => $date->toDateString(),
            // Both ends, because "week of 11 Aug" is ambiguous the moment the
            // week does not start on the day the reader assumes it does.
            'label' => $date->format('D j M Y').' → '.$date->addDays(6)->format('D j M'),
            'current' => $date->equalTo($thisWeek),
        ])->all();
    }

    /**
     * Drag a shift to another day or person.
     *
     * JSON in, JSON out: the grid posts and reloads rather than trying to keep
     * a client-side model in step with the server. There is no second source
     * of truth to drift.
     */
    public function moveShift(Request $request, Shift $shift): JsonResponse
    {
        return $this->drag($request, fn (?string $date, mixed $employeeId): Shift => $this->shifts->move($shift, $date, $employeeId));
    }

    /** The same drop, holding Ctrl or Alt: the original stays put. */
    public function copyShift(Request $request, Shift $shift): JsonResponse
    {
        return $this->drag($request, fn (?string $date, mixed $employeeId): Shift => $this->shifts->copy($shift, $date, $employeeId));
    }

    /**
     * @param  \Closure(?string, mixed): Shift  $action
     */
    private function drag(Request $request, \Closure $action): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            // Distinguishes "drop on the open-shifts row" from "leave the
            // employee alone". Without it, a null employee_id is ambiguous.
            'unassign' => ['nullable', 'boolean'],
        ]);

        $employeeId = match (true) {
            (bool) ($data['unassign'] ?? false) => null,
            ($data['employee_id'] ?? null) !== null => (int) $data['employee_id'],
            default => false,
        };

        try {
            $shift = $action($data['business_date'] ?? null, $employeeId);

            /*
             * THE ROLE FOLLOWS THE PERSON, and a drop onto another row IS a
             * reassignment. move() and copy() both carry the position over
             * untouched, which was right while a manager picked it — the shift
             * kept what somebody chose for it — and is wrong now that it belongs
             * to whoever is on it: dragging a Crew Member's shift onto an
             * Assistant Manager left it costed and published as Crew Member,
             * with nothing on screen saying so.
             *
             * Only when a PERSON is named. A drop on the open-shifts row keeps
             * the role, because that is the whole content of an open slot, and a
             * drag that does not touch the employee has nothing to re-read.
             */
            if ($employeeId !== false && $employeeId !== null) {
                $derived = $this->plannedPositionId(
                    (int) $shift->store_id,
                    (int) $employeeId,
                    $this->dateOf($shift->business_date),
                );

                // Null leaves the shift's own role alone rather than clearing it,
                // for the same reason updateShift() does — see the note there.
                if ($derived !== null && $derived !== (int) $shift->position_id) {
                    $shift = $this->shifts->update($shift, ['position_id' => $derived]);
                }
            }

            return response()->json([
                'ok' => true,
                'shift_id' => (int) $shift->id,
                'business_date' => $this->dateOf($shift->business_date),
            ]);
        } catch (SchedulingException $e) {
            // 422, not 500: a refused drop is an answer, not a fault.
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => class_basename($e).': '.$e->getMessage(),
            ], 500);
        }
    }

    private function dateOf(mixed $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
    }

    /**
     * THE HEARTBEAT. Answers one question — "has anything changed?" — and
     * refreshes the range from TCP while it is there.
     *
     * This is what replaced "Pull the week's actual hours". A button made the
     * board's currency somebody's chore, and an out-of-date grid looks exactly
     * as settled as a current one, so the mistake was invisible. See
     * LiveSegmentFeed for why the vendor call happens HERE, inside a poll
     * nobody is waiting on, rather than in the page render or a queued job.
     *
     * ALWAYS 200, EVEN WHEN TCP IS DOWN. A polling loop that gets an error
     * status is a polling loop that stops; the failure belongs in the payload,
     * where the status pill can show it and the next tick can still run.
     *
     * GET, and it changes no state of ours — it only reads from the vendor into
     * a table that is a mirror of the vendor. Nothing here is a domain write,
     * which is why it is safe to have a page call it every few seconds.
     */
    public function live(Request $request): JsonResponse
    {
        /*
         * Validator::make, NOT $request->validate().
         *
         * This is a console route, and bootstrap/app.php deliberately renders
         * JSON only for api/*  — so a ValidationException thrown here comes back
         * as a 302 to the referring page. For an ordinary form that is exactly
         * right. For a polling endpoint it is poison: the browser follows the
         * redirect, gets HTML, fails to parse it, and the heartbeat reports the
         * console as unreachable when the only thing wrong was a query string.
         *
         * So the failure is handled rather than thrown, and every response from
         * this action is JSON whatever happens.
         */
        $validator = Validator::make($request->query(), [
            'store' => ['required', 'integer', 'exists:stores,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            // A day board polls with from === to. One request either way: the
            // TCP filter takes a range.
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                // No fingerprint on purpose: there is no range to have one for,
                // and a made-up value would tell the page its grid had changed.
                'fingerprint' => null,
            ], 422);
        }

        $data = $validator->validated();

        return response()->json(
            $this->live->poll((int) $data['store'], $data['from'], $data['to'])
        );
    }

    /**
     * Pull the day's ACTUAL hours from TCP.
     *
     * The counterpart to publish, and the direction matters:
     *
     *   PLANNED shifts go OUT to Humanity   (POST/PUT /shifts)
     *   ACTUAL hours come IN from TCP       (GET /worksegments)
     *
     * Nothing crosses over. A planned shift is never sent to TCP as worked time,
     * and a punch is never sent to Humanity as a plan. The only writes back to
     * TCP are the document's own correction workflows — approve, change times,
     * create-for-a-missed-clock-in, delete — each on a single segment a manager
     * touched, never a batch.
     *
     * Idempotent: the upsert is keyed on tcp_segment_id, so re-pulling a day
     * costs a request and changes nothing.
     */
    public function pullSegments(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            // The week view sends a span. Absent, it is the single day the day
            // board asks for — one request either way, because the filter takes
            // a range and a week's employee list is the same list seven times.
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
        ]);

        $to = $data['to'] ?? $data['date'];
        $span = $to === $data['date'] ? $data['date'] : $data['date'].' to '.$to;

        try {
            $result = $this->segmentSync->syncRange($data['date'], $to, (int) $data['store_id']);
        } catch (Throwable $e) {
            return back()->with('err', 'TCP pull failed — '.class_basename($e).': '.$e->getMessage());
        }

        $parts = [];
        foreach (['created', 'updated', 'unchanged', 'held'] as $key) {
            if ((int) ($result[$key] ?? 0) > 0) {
                $parts[] = $result[$key].' '.$key;
            }
        }

        $message = ((int) ($result['fetched'] ?? 0) === 0)
            ? 'TCP returned no punches for '.$span.'.'
            : 'Pulled '.$result['fetched'].' from TCP for '.$span
                .($parts === [] ? '' : ': '.implode(', ', $parts)).'.';

        // 'held' is not a failure but it is not silence either: TCP disagreed
        // with a row a human had already approved or corrected, and we kept
        // ours. Somebody should know the two systems differ.
        if ((int) ($result['held'] ?? 0) > 0) {
            $message .= ' Held rows kept the local approval or correction over the TCP version.';
        }

        // skipped is a LIST of reasons, not a count. Rows TCP sent that we
        // refused — reporting a clean pull would hide them.
        $skipped = $result['skipped'] ?? [];

        if ($skipped !== []) {
            $why = collect($skipped)
                ->map(fn ($row) => is_array($row) ? ($row['reason'] ?? 'unknown') : (string) $row)
                ->countBy()
                ->map(fn (int $n, string $reason) => "{$reason} ×{$n}")
                ->join(', ');

            return back()->with('err', $message.' Skipped: '.$why.'.');
        }

        return back()->with('ok', $message);
    }

    /**
     * Send the visible range to Humanity. The only button on this console that
     * talks to Humanity at all.
     *
     * Everything up to here is local, because a POST to Humanity is live the
     * instant it lands. Safe to press twice: a shift whose fingerprint still
     * matches is reported unchanged and costs no request.
     */
    public function publish(Request $request): RedirectResponse
    {
        // Shared with the two bulk actions, so the three range controls on this
        // console cannot disagree about what "in view" means.
        $data = $this->validateRange($request);

        try {
            $result = $this->publisher->publishRange((int) $data['store_id'], $data['from'], $data['to']);
        } catch (Throwable $e) {
            return back()->with('err', class_basename($e).': '.$e->getMessage());
        }

        if ($result['total'] === 0) {
            return back()->with('ok', 'Nothing to publish — every shift in view is already live and unchanged.');
        }

        $parts = [];
        foreach (['created' => 'created', 'updated' => 'updated', 'unchanged' => 'unchanged', 'failed' => 'failed'] as $key => $word) {
            if (($result[$key] ?? 0) > 0) {
                $parts[] = $result[$key].' '.$word;
            }
        }

        $message = 'Published '.$data['from'].' to '.$data['to'].': '.implode(', ', $parts).'.';

        // A partial failure is still a failure worth surfacing loudly — some of
        // the store's week is live and some is not.
        return back()->with(($result['failed'] ?? 0) > 0 ? 'err' : 'ok', $message);
    }

    /**
     * Unlock one published shift so it can be edited.
     *
     * Humanity keeps the shift and we keep its id, so the next publish sends a
     * PUT rather than creating a duplicate. Employees see the last published
     * version until then.
     */
    /**
     * Unlock every published shift in the visible range for editing.
     *
     * ONE BUTTON FOR THE WEEK, where there used to be a padlock on every chip.
     * The rule has not changed — a published shift cannot be edited, moved or
     * deleted until it is unlocked — but the grain was wrong: the workflow is
     * "unpublish, change the week, republish", and doing it a shift at a time
     * meant fourteen clicks before a manager could touch anything.
     *
     * NOTHING IS SENT TO HUMANITY. Unlocking is local: the shifts stay live on
     * everybody's roster exactly as they are, and the next publish sends the
     * changes as a PUT over the same shift. This is the sentence the flash has
     * to carry, because "unpublish" reads like a withdrawal and is not one.
     */
    public function unpublishShifts(Request $request): RedirectResponse
    {
        $data = $this->validateRange($request);

        try {
            $result = $this->range->unpublishRange(
                (int) $data['store_id'],
                $data['from'],
                $data['to'],
            );
        } catch (SchedulingException $e) {
            return back()->with('err', $e->getMessage());
        } catch (Throwable $e) {
            return back()->with('err', class_basename($e).': '.$e->getMessage());
        }

        if ($result['unlocked'] === 0) {
            return back()->with('ok', $result['total'] === 0
                ? 'No shifts in view to unpublish.'
                : 'Nothing to unpublish — no shift in view is locked.');
        }

        return back()->with('ok', $result['unlocked'].' shift'.($result['unlocked'] === 1 ? '' : 's')
            .' unpublished and editable. '
            .($result['unlocked'] === 1 ? 'It is' : 'They are').' still live in Humanity — '
            .'republish to send the changes as PUTs over the same shifts.');
    }

    /**
     * Delete every shift in the visible range.
     *
     * SCOPED TO WHAT IS ON SCREEN, and the count is in the button's own label so
     * the scope cannot be misread. "All" here means the store and the span the
     * board is showing, never the table.
     *
     * Humanity is told first and a shift whose withdrawal fails is NOT deleted —
     * see ShiftRangeService::deleteRange() for why that order is the only safe
     * one. A partial failure is reported loudly and leaves the rest of the week
     * deleted, because the shifts that did come out of Humanity are gone there
     * and must be gone here too.
     */
    public function destroyShifts(Request $request): RedirectResponse
    {
        $data = $this->validateRange($request);

        try {
            $result = $this->range->deleteRange(
                (int) $data['store_id'],
                $data['from'],
                $data['to'],
            );
        } catch (SchedulingException $e) {
            return back()->with('err', $e->getMessage());
        } catch (Throwable $e) {
            return back()->with('err', class_basename($e).': '.$e->getMessage());
        }

        if ($result['total'] === 0) {
            return back()->with('ok', 'No shifts in view to delete.');
        }

        $message = $result['deleted'].' shift'.($result['deleted'] === 1 ? '' : 's').' deleted.';

        if ($result['withdrawn'] > 0) {
            $message .= ' '.$result['withdrawn'].' withdrawn from Humanity, so nobody is still rostered for '
                .($result['withdrawn'] === 1 ? 'it' : 'them').'.';
        }

        // Soft delete, so the punches keep pointing at the rows. Said out loud
        // rather than left for somebody to wonder about: the hours are not gone.
        if ($result['punches'] > 0) {
            $message .= ' '.$result['punches'].' punch(es) still reference the deleted shifts, so the pairings '
                .'survive a restore.';
        }

        if ($result['failures'] === []) {
            return back()->with('ok', $message);
        }

        // NAMED, NOT COUNTED. These are shifts Humanity would not release, which
        // means they are still on somebody's roster and still on this board — the
        // manager can press delete again once the vendor is reachable.
        $named = collect($result['failures'])
            ->take(5)
            ->map(fn (array $row): string => '#'.$row['shift_id'])
            ->join(', ');

        return back()->with('err', $message.' '.count($result['failures']).' could NOT be withdrawn from Humanity ('
            .$named.(count($result['failures']) > 5 ? ', …' : '').') and were left in place — they are still live '
            .'there. '.($result['failures'][0]['reason'] ?? ''));
    }

    /**
     * The store and span both bulk actions take.
     *
     * Identical to what publish() validates, and on purpose: the three range
     * actions on this console must agree about what "in view" means, or one of
     * them acts on a different set of shifts than the label beside it claims.
     *
     * @return array<string,string>
     */
    private function validateRange(Request $request): array
    {
        return $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    public function storeShift(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            /*
             * ACCEPTED ONLY FOR AN OPEN SHIFT, and overridden otherwise.
             *
             * When there is a person on the shift the role is THEIRS and comes
             * from their profile — hiring first, then TCP's own assignment; see
             * plannedPositionId(). When there is not, there is
             * nobody to inherit from and the role IS the shift's whole content:
             * "we need a Driver on Friday" is the entire point of an open slot,
             * and Humanity refuses a shift with no position on it. So this one
             * field survives the removal of the dropdowns, for the one case
             * that has no employee in it.
             */
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // An end at or before the start is a deliberate overnight shift, not a
        // mistake — roll it to the next calendar day rather than rejecting it.
        $endDate = $data['end'] <= $data['start']
            ? now()->parse($data['date'])->addDay()->toDateString()
            : $data['date'];

        $storeId = (int) $data['store_id'];
        $employeeId = $data['employee_id'] ?? null;

        /*
         * THE PERSON'S RECORD WINS over anything the request carried — a stale
         * page or a hand-rolled POST cannot book somebody as a role their profile
         * does not give them, which is the class of bug the dropdown kept
         * producing.
         *
         * AND NO ANSWER IS NOT A LICENCE TO GUESS. This used to fall through to
         * the request's position_id, which by then came from a select the manager
         * could not see — so anybody hiring and TCP both knew nothing about was
         * rostered as whatever came first in the list. That shift published, and
         * costed, under a role nobody chose. A null position instead leaves the
         * shift visibly roleless: the publish refuses it by name and says where to
         * set it, which is hiring.
         */
        $positionId = $employeeId === null
            ? ($data['position_id'] ?? null)
            : $this->plannedPositionId($storeId, (int) $employeeId, $data['date']);

        return $this->attempt($request, fn () => $this->shifts->create([
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'position_id' => $positionId,
            'start_at_local' => "{$data['date']} {$data['start']}:00",
            'end_at_local' => "{$endDate} {$data['end']}:00",
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $this->actingUser->id(),
        ]), 'Shift added.');
    }

    /**
     * Edit a PLANNED shift. Local only — nothing reaches Humanity here.
     *
     * "The whole scheduling will be handled on our platform until the user hit
     * publish." A POST to Humanity goes live the moment it lands, so an edit
     * must not push. ShiftService::update nulls payload_fingerprint when a
     * Humanity-visible field changes, which is what makes the NEXT publish run
     * re-send this shift instead of skipping it as unchanged.
     */
    public function updateShift(Request $request, Shift $shift): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $endDate = $data['end'] <= $data['start']
            ? now()->parse($data['date'])->addDay()->toDateString()
            : $data['date'];

        $wasPublished = $shift->publish_state === PublishState::Published;

        $attributes = [
            'store_id' => $shift->store_id,
            'start_at_local' => "{$data['date']} {$data['start']}:00",
            'end_at_local' => "{$endDate} {$data['end']}:00",
        ];

        /**
         * PRESENT-OR-ABSENT, not `?? null`.
         *
         * A key the request did not send means "leave this alone". A key it sent
         * empty means "clear it". Collapsing the two wiped the POSITION off any
         * shift edited by a caller that did not resend it — the edit form always
         * does, which is why it went unnoticed — and a shift with no position
         * cannot be published at all, because Humanity requires a schedule (its
         * name for a position) on every shift. It looked like a publish failure
         * long after the edit that caused it.
         *
         * This is the same distinction the JSON API draws with `sometimes`, and
         * ShiftService::update already honours it: it fills only the keys it is
         * given.
         */
        /*
         * position_id IS NO LONGER TAKEN FROM THE REQUEST when there is an
         * employee on the shift. Assigning somebody re-derives their role from
         * their profile — hiring first, then TCP's assignment, see
         * plannedPositionId(); clearing the employee leaves whatever the open
         * slot is for.
         *
         * Resolved before the loop below so it participates in the same
         * present-or-absent contract: a request that says nothing about the
         * employee changes neither the employee nor the role.
         */
        if (array_key_exists('employee_id', $data)) {
            $employeeId = $data['employee_id'] === null ? null : (int) $data['employee_id'];

            $derived = $employeeId === null
                ? null
                : $this->plannedPositionId(
                    (int) $shift->store_id,
                    $employeeId,
                    $data['date'] ?? $this->dateOf($shift->business_date),
                );

            /*
             * ONLY WHEN THE PROFILE ACTUALLY HAS AN ANSWER. Writing a null here would
             * CLEAR the position off the row, and a shift with no position cannot
             * be published at all — Humanity requires a schedule (its name for a
             * position) on every one. That is the same fault the present-or-absent
             * note below was written for, arriving by a new route: it would show
             * up as a publish failure long after the edit that caused it, at any
             * store outside TCP or for anybody whose assignments had not synced.
             *
             * So a missing profile role leaves the shift's own role alone — the
             * role it was created with, which is still nobody's guess.
             */
            if ($derived !== null) {
                $data['position_id'] = $derived;
            } else {
                // Not ours to change, and not the request's either: the form no
                // longer offers the field, so anything arriving in it is stale.
                unset($data['position_id']);
            }
        }

        foreach (['employee_id', 'position_id', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        return $this->attempt($request, function () use ($shift, $attributes) {
            return $this->shifts->update($shift, $attributes);
        }, "Shift #{$shift->id} updated locally."
            .($wasPublished
                ? ' It is already in Humanity, so the next publish run will send the change.'
                : ' Nothing has been sent to Humanity — it is still a draft.'));
    }

    /**
     * Create an ACTUAL shift by hand: the document's "forgot to clock in"
     * workflow, and the one piece of segment CRUD the console did not have.
     *
     * origin = manual_create and tcp_segment_id stays NULL until the POST to TCP
     * succeeds, so a failed push leaves visible hours behind rather than losing
     * them. Leave the clock-out empty to record somebody who is still in the
     * store — that is an open punch, not an incomplete form.
     */
    public function storeSegment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            /*
             * NO position_id. It used to be REQUIRED here — TCP will not take
             * hours without a jobCodeId, and the code says which role was worked
             * — but requiring it meant asking a manager to name something TCP
             * already knows, and getting it wrong three different ways: roles
             * TCP has nowhere, roles it has at one store only, and stores whose
             * number cannot form a code.
             *
             * The role now comes from TCP's own assignment for this person at
             * this store. See TcpEmployeeJobCode and the migration that
             * introduced it.
             */
            'date' => ['required', 'date_format:Y-m-d'],
            'time_in' => ['required', 'date_format:H:i'],
            // A clock-out BEFORE the clock-in is a punch that ran past midnight
            // and rolls forward. One EQUAL to it is a typo, not a 24-hour shift.
            'time_out' => ['nullable', 'date_format:H:i', 'different:time_in'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * REFUSED WHILE SOMEBODY IS LOOKING AT THE FORM, not at payroll.
         *
         * The old guard here asked whether the POSITION a manager picked was one
         * this store could file. There is no position to pick any more, so the
         * question became the one that replaced it: has TCP assigned this person
         * a job code at this store? Without one there is no jobCodeId to send,
         * and hours that cannot be sent are hours that sit on the board looking
         * recorded while payroll never sees them.
         */
        $this->guardEmployeeHasJobCode((int) $data['store_id'], (int) $data['employee_id']);

        $outDate = ($data['time_out'] ?? null) !== null && $data['time_out'] < $data['time_in']
            ? now()->parse($data['date'])->addDay()->toDateString()
            : $data['date'];

        $open = ($data['time_out'] ?? null) === null;

        return $this->attempt($request, fn () => $this->segments->create([
            'store_id' => (int) $data['store_id'],
            'employee_id' => (int) $data['employee_id'],
            // Derived, not submitted. The column stays — the estimator and the
            // chips read it — but its value is TCP's, not a manager's.
            //
            // Null is a real outcome at a store TCP has never heard of, which is
            // deliberately still allowed to record hours; the punch saves and its
            // chip says why it cannot be pushed. That is the same behaviour as
            // before, reached without asking anybody to pick a role first.
            'position_id' => $this->derivedPositionId((int) $data['store_id'], (int) $data['employee_id']),
            // _local, not the bare column: the form collects store wall clock,
            // and the service converts. Passing time_in here would read 09:30 as
            // UTC and file the punch at the store's offset.
            'time_in_local' => "{$data['date']} {$data['time_in']}:00",
            'time_out_local' => $open ? null : "{$outDate} {$data['time_out']}:00",
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'notes' => $data['notes'] ?? null,
        ]), $open
            ? 'Open punch recorded — clocked in, still in the store. It has no hours to approve yet.'
            : 'Actual hours recorded and queued for TCP. They are unapproved until somebody signs them off.');
    }

    /**
     * Edit an ACTUAL shift: the document's Change Shift workflow.
     *
     * Saved locally, then pushed to TCP by a queued job. A correction clears
     * manager_approval unless the caller re-approves, so hours nobody has
     * reviewed since the change cannot sit there marked approved.
     */
    public function updateSegment(Request $request, WorkSegment $segment): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'reapprove' => ['nullable', 'boolean'],
            /*
             * THE REPAIR PATH IS GONE, because what it repaired cannot happen
             * any more. It existed so a punch recorded against a role TCP had no
             * code for could be re-filed without deleting evidence of worked
             * hours — a dropdown could produce such a punch. The role now comes
             * from TCP's own assignment, so a punch that saved has a code that
             * exists, and a correction only ever moves the clocks.
             *
             * If TCP moves somebody to a different job code, re-syncing picks it
             * up and the fix belongs there, not in a dialog on this board.
             */
        ]);

        $outDate = ($data['time_out'] ?? null) !== null && $data['time_out'] <= $data['time_in']
            ? now()->parse($data['date'])->addDay()->toDateString()
            : $data['date'];

        $reapprove = (bool) ($data['reapprove'] ?? false);
        $storeId = (int) $segment->store_id;

        // correctTimes() takes UTC INSTANTS — a bare string is parsed as UTC,
        // not as store-local. The form collects wall-clock time, so it has to
        // be converted here or a 09:30 correction lands as 05:30 and every
        // edit silently shifts by the store's offset.
        $timeIn = $this->businessDay->combine($storeId, $data['date'], $data['time_in'].':00');
        $timeOut = ($data['time_out'] ?? null) === null
            ? null
            : $this->businessDay->combine($storeId, $outDate, $data['time_out'].':00');

        return $this->attempt($request, fn () => $this->segments->correctTimes(
            $segment,
            $timeIn,
            $timeOut,
            $reapprove,
            $this->actingUser->id(),
        ), "Segment #{$segment->id} corrected and queued for TCP."
            .($reapprove ? ' Re-approved as instructed.' : ' Approval cleared — it needs reviewing again.'));
    }

    /**
     * Create the next part of a split.
     *
     * Takes explicit wall-clock times rather than a gap, because a gap hides
     * the thing most likely to surprise you: three hours after a shift ending
     * at 22:00 puts part 2 at 01:00, on the NEXT business_date, where it drops
     * off this board entirely. With real times the dialog can say so before you
     * commit. A gap/length pair is still accepted for scripted callers.
     */
    public function splitShift(Request $request, Shift $shift): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'second_start' => ['nullable', 'date_format:H:i'],
            'second_end' => ['nullable', 'date_format:H:i'],
            'gap_minutes' => ['nullable', 'integer', 'min:1', 'max:720'],
            'length_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
        ]);

        $storeId = (int) $shift->store_id;

        if (($data['second_start'] ?? null) !== null && ($data['second_end'] ?? null) !== null) {
            // The day part 1 ENDS on, not its business_date: a part 1 that
            // already runs past midnight has its successor on the later day.
            $baseDate = $data['date']
                ?? $this->businessDay->toLocal($storeId, $shift->end_at)->toDateString();

            $start = $this->businessDay->combine($storeId, $baseDate, $data['second_start'].':00');
            $end = $this->businessDay->combine($storeId, $baseDate, $data['second_end'].':00');

            // Both ends roll forward together when the block crosses midnight,
            // and the start rolls on its own when it lands before part 1 ends.
            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }

            if ($start->lessThan($shift->end_at)) {
                $start = $start->addDay();
                $end = $end->addDay();
            }
        } else {
            $gap = (int) ($data['gap_minutes'] ?? 180);
            $length = (int) ($data['length_minutes'] ?? 180);

            // copy() on every step: the datetime cast is a MUTABLE Carbon, so
            // arithmetic on end_at would rewrite the model's own attribute and
            // hand back the same instance for both ends.
            $start = $shift->end_at->copy()->addMinutes($gap);
            $end = $start->copy()->addMinutes($length);
        }

        $gapMinutes = (int) $shift->end_at->diffInMinutes($start);
        $newDay = $this->businessDay->businessDate($storeId, $start);

        // Compare against part 1's OWN business_date, not the day it ends on.
        // Those differ precisely when part 1 already crosses midnight, which is
        // the case most likely to strand part 2 on a date nobody is looking at.
        $part1Day = $shift->business_date instanceof CarbonInterface
            ? $shift->business_date->toDateString()
            : (string) $shift->business_date;
        $sameDay = $newDay === $part1Day;

        return $this->attempt(
            $request,
            fn () => $this->shifts->split($shift, $start, $end),
            "Shift #{$shift->id} split: part 2 runs "
                .$this->businessDay->toLocal($storeId, $start)->format('H:i')
                .' to '.$this->businessDay->toLocal($storeId, $end)->format('H:i')
                .", after a {$gapMinutes} minute unpaid gap that is not a break."
                .($sameDay ? '' : " It falls on {$newDay}, so open that date to see it."),
        );
    }

    public function destroyShift(Request $request, Shift $shift): RedirectResponse
    {
        $rule = $request->input('rule', 'following');

        // Soft delete, so punches keep pointing at the row and the
        // reconciliation survives — shift_id only drops to NULL on a HARD
        // delete, which is when the ON DELETE SET NULL rule actually fires.
        // Saying "shift_id NULL" here would be a lie about what just happened.
        $keptPunches = $shift->workSegments()->count();

        /**
         * WITHDRAW FROM HUMANITY BEFORE DELETING LOCALLY.
         *
         * ShiftService::delete is local only — its own class docblock says
         * nothing in it talks to Humanity — and nothing else called
         * SchedulePublisher::withdraw(). So deleting a published shift removed
         * it from the board and left it live on the employee's roster, with the
         * row that held its humanity_shift_id soft-deleted, which means nothing
         * could ever have cleaned it up. Somebody turns up for a shift that was
         * cancelled a week ago.
         *
         * EVERY OCCURRENCE THIS DELETE WILL TAKE, not just the one clicked: a
         * series delete removes rows for dates the manager never looked at, and
         * each published one is its own Humanity shift with its own id.
         */
        $doomed = $shift->series_id === null
            ? Shift::query()->whereKey($shift->getKey())->get()
            : Shift::query()->inSeries(
                (string) $shift->series_id,
                $rule === 'following' ? $this->dateOf($shift->business_date) : null,
            )->get();

        /**
         * humanity_shift_id, NOT publish_state, and the difference is a bug I
         * nearly shipped. A row that failed mid-publish keeps its id —
         * recordFailure() only writes the error — so it is 'failed' AND still
         * held by Humanity. Filtering on isLive() would skip exactly the shift
         * whose delete had already been tried once and left behind.
         *
         * The id is the only honest test: withdraw() nulls it, so a row that has
         * one is a row Humanity is holding.
         */
        $held = $doomed->filter(fn (Shift $row): bool => $row->humanity_shift_id !== null);

        return $this->attempt(
            $request,
            function () use ($shift, $rule, $held) {
                /**
                 * THE GATE BEFORE THE VENDOR CALL, and the order is the whole
                 * point. ShiftService::delete() refuses a published shift, but
                 * it runs LAST — so asking it after the withdraw loop would pull
                 * the shift off the employee's roster and then refuse to delete
                 * it here, leaving the two systems disagreeing in the one
                 * direction that matters.
                 *
                 * Same rule, same class, just asked early enough to be useful.
                 */
                $this->shifts->assertCanDelete($shift);

                /**
                 * Humanity first, and the local delete is abandoned if it fails.
                 *
                 * The other order loses the shift off the board while the vendor
                 * keeps it — and once the row is gone there is nothing left to
                 * retry with. Refusing leaves the manager a shift they can try
                 * to cancel again, which is the recoverable half of the trade.
                 *
                 * A partial sweep is recoverable too: occurrences already
                 * withdrawn are Unpublished and still on the board, so pressing
                 * delete again finishes the job.
                 */
                foreach ($held as $row) {
                    // No rule passed: we never send `repeat` on a create, so each
                    // Humanity shift stands alone and the vendor's own series
                    // rule has nothing to act on.
                    $this->publisher->withdraw($row);
                }

                return $this->shifts->delete($shift, $rule);
            },
            "Shift #{$shift->id} soft-deleted (rule: {$rule})."
                .($held->isNotEmpty()
                    ? ' '.$held->count().' shift(s) withdrawn from Humanity, so nobody is still rostered for it.'
                    : ' Humanity was not holding it, so nothing was sent.')
                .($keptPunches > 0
                    ? " Its {$keptPunches} punch(es) still reference it, so the pairing survives a restore."
                    : ''),
        );
    }

    /** Clock someone in against a shift, two minutes early, as people do. */
    public function punchIn(Request $request, Shift $shift): RedirectResponse
    {
        if ($shift->employee_id === null) {
            return back()->with('err', 'An open shift has nobody to clock in.');
        }

        return $this->attempt($request, fn () => $this->segments->create([
            'store_id' => $shift->store_id,
            'employee_id' => $shift->employee_id,
            'position_id' => $shift->position_id,
            // copy(): a mutable Carbon here would rewind the shift's own start_at.
            'time_in' => $shift->start_at->copy()->subMinutes(2),
        ]), "Clocked in against shift #{$shift->id}.");
    }

    public function punchOut(Request $request, WorkSegment $segment): RedirectResponse
    {
        $out = $segment->shift?->end_at?->copy()->addMinutes(3)
            ?? $segment->time_in->copy()->addHours(4);

        return $this->attempt(
            $request,
            fn () => $this->segments->correctTimes($segment, null, $out),
            'Clocked out. The hours are now approvable.',
        );
    }

    public function approveSegment(Request $request, WorkSegment $segment): RedirectResponse
    {
        return $this->attempt(
            $request,
            fn () => $this->segments->approve($segment, $this->actingUser->id()),
            "Segment #{$segment->id} approved.",
        );
    }

    public function destroySegment(Request $request, WorkSegment $segment): RedirectResponse
    {
        return $this->attempt($request, fn () => $this->segments->delete($segment), 'Punch deleted.');
    }

    /**
     * File a request from the console, on somebody's behalf.
     *
     * Employees have no login here, so a manager typing it is the normal case —
     * which is exactly why employee_id (the SUBJECT) and requested_by_user_id
     * (who TYPED it) are two columns. The API accepts the same request from an
     * employee-facing client; both land in the same table and are told apart by
     * that second column, not by a separate route.
     *
     * Always born pending. There is no "approve as you file it" here: approval
     * is a decision, and a decision leaves a row behind.
     */
    public function storeRequest(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'request_type' => ['required', Rule::enum(RequestType::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);

        return $this->attempt($request, fn () => $this->requests->create(array_merge($data, [
            // From the session, never the form: whoever is acting is who typed
            // it, and a form that could name the filer could name anyone.
            'requested_by_user_id' => $this->actingUser->id(),
        ])), 'Request filed, and pending. It needs a decision before it affects the schedule.');
    }

    /**
     * Fix a request nobody has ruled on yet.
     *
     * The service refuses this once a decision exists — see
     * EmployeeRequestService::update(). Wrong dates on an approved request are
     * corrected by withdrawing it and filing the right one, which is the
     * version that leaves a trail.
     */
    public function updateRequest(Request $request, EmployeeRequest $employeeRequest): RedirectResponse
    {
        $data = $request->validate([
            'request_type' => ['sometimes', Rule::enum(RequestType::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'exists:shifts,id'],
        ]);

        return $this->attempt(
            $request,
            fn () => $this->requests->update($employeeRequest, $data),
            "Request #{$employeeRequest->id} corrected. It is still pending.",
        );
    }

    /**
     * Withdraw a request. Appends a cancelled decision; deletes nothing.
     */
    public function withdrawRequest(Request $request, EmployeeRequest $employeeRequest): RedirectResponse
    {
        $notes = $request->filled('notes') ? (string) $request->input('notes') : null;
        $wasApproved = $employeeRequest->status === RequestStatus::Approved;

        return $this->attempt(
            $request,
            fn () => $this->requests->withdraw($employeeRequest, $this->actingUser->id(), $notes),
            "Request #{$employeeRequest->id} withdrawn."
                .($wasApproved
                    ? ' It was approved, so the time off it protected is now free to schedule over.'
                    : ' The decision trail keeps it.'),
        );
    }

    public function decideRequest(Request $request, EmployeeRequest $employeeRequest): RedirectResponse
    {
        $decision = RequestDecision::tryFrom((string) $request->input('decision'));

        if ($decision === null) {
            return back()->with('err', 'Unknown decision.');
        }

        $notes = $request->filled('notes') ? (string) $request->input('notes') : null;

        // Whether this is the first ruling or a reversal, so the flash can say
        // which. Read before the write.
        $revision = $employeeRequest->decisions()->count() + 1;

        return $this->attempt(
            $request,
            fn () => $this->requests->decide($employeeRequest, $decision, $this->actingUser->id(), $notes),
            $revision === 1
                ? "Request #{$employeeRequest->id} {$decision->value}."
                : "Request #{$employeeRequest->id} changed to {$decision->value}. "
                    .'The previous decision is kept in the trail.',
        );
    }

    /**
     * Per-store scheduling settings.
     *
     * These were seeder-only until now, which meant a store arriving from auth
     * could not be configured without a deploy — and timezone is the column
     * that decides which day every shift is filed under.
     */
    public function settings(Request $request): View
    {
        $stores = Store::query()->orderBy('id')->get();
        $storeId = (int) ($request->query('store') ?: $stores->first()?->id ?: DemoSeeder::STORE_ID);

        return view('board.settings', [
            'stores' => $stores,
            'storeId' => $storeId,
            'setting' => $this->settings->forStore($storeId),
            // Grouped so the picker is navigable; a flat list of 400-odd zones
            // is not something anybody scrolls.
            'timezones' => collect(timezone_identifiers_list())
                ->filter(fn (string $zone): bool => str_starts_with($zone, 'America/')
                    || str_starts_with($zone, 'Pacific/')
                    || str_starts_with($zone, 'US/'))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Save them.
     *
     * A timezone change is loud on purpose — see StoreSettingService, and the
     * flash below. It cannot reach the queue workers' own static caches.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'timezone' => ['required', 'string', 'timezone'],
            'publish_lead_days' => ['required', 'integer', 'min:0', 'max:365'],
            'auto_publish' => ['nullable', 'boolean'],
        ]);

        $storeId = (int) $data['store_id'];
        $before = $this->settings->forStore($storeId)->timezone;

        $changed = $before !== $data['timezone'];

        return $this->attempt($request, fn () => $this->settings->update($storeId, [
            'timezone' => $data['timezone'],
            'publish_lead_days' => (int) $data['publish_lead_days'],
            'auto_publish' => (bool) ($data['auto_publish'] ?? false),
        ]), $changed
            ? "Settings saved. Timezone moved from {$before} to {$data['timezone']} — shifts already "
                .'filed keep the business date they were saved under, and only new ones use the new zone. '
                .'Restart any running queue workers: they cache the old zone for the life of the process.'
            : 'Settings saved.');
    }

    public function reseed(): RedirectResponse
    {
        Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

        return redirect()->route('board')->with('ok', 'Demo data reset.');
    }

    /**
     * Run a service call, turn a domain refusal into a readable message rather
     * than a stack trace. SchedulingException is the domain saying no on
     * purpose — approving an open punch, ending a shift before it starts — and
     * the console should show exactly that sentence.
     */
    private function attempt(Request $request, callable $work, string $success): RedirectResponse
    {
        try {
            $work();

            return back()->with('ok', $success);
        } catch (SchedulingException $e) {
            return back()->with('err', $e->getMessage());
        } catch (Throwable $e) {
            return back()->with('err', class_basename($e).': '.$e->getMessage());
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function roster(int $storeId, string $date): array
    {
        return Employee::query()
            ->with('availabilityWindows')
            // The same definition of "belongs to this store" the API uses — see
            // EmployeeController::index(). It differed here in both halves, and
            // both differences put the wrong people on the board:
            //
            //   SCHEDULABLE. Hiring publishes no employee.deleted event, so a
            //   termination arrives as a status change and the row stays. Without
            //   this, everyone who ever left the store is still on the board.
            //
            //   ASSIGNMENTS. People cover stores that are not their primary one,
            //   and a roster that only reads primary_store_id cannot staff a
            //   Saturday. The nested closure keeps the OR from escaping and
            //   swallowing the status filter.
            ->schedulable()
            ->where(fn ($query) => $query
                ->where('primary_store_id', $storeId)
                ->orWhereHas('storeAssignments', fn ($assignment) => $assignment->where('store_id', $storeId)))
            ->orderBy('first_name')
            ->get()
            ->map(function (Employee $employee) use ($date): array {
                $rate = $this->costs->rateOn($employee->id, $date);
                $dow = strtolower(now()->parse($date)->format('l'));

                return [
                    'model' => $employee,
                    'age' => $employee->birth_date?->diffInYears(now()->parse($date)),
                    'rate' => $rate === null ? null : (float) $rate->base_pay + (float) $rate->performance_pay,
                    // day_of_week is an enum cast, so compare the backing
                    // value rather than relying on a dotted string path.
                    'windows' => $employee->availabilityWindows
                        ->filter(fn ($w): bool => $w->day_of_week?->value === $dow)
                        ->values(),
                ];
            })
            ->all();
    }
}
