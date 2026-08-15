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
use App\Models\User;
use App\Models\WorkSegment;
use App\Services\Scheduling\BoardService;
use App\Services\Scheduling\EmployeeRequestService;
use App\Services\Scheduling\LaborCostEstimator;
use App\Services\Scheduling\SchedulePublisher;
use App\Services\Scheduling\ShiftService;
use App\Services\Scheduling\StoreSettingService;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
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

    /** The store+range the punches were last read from TCP for. See pullSegmentsOnRangeChange(). */
    private const SEGMENT_PULL_KEY = 'tcp_segment_pull_range';

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
            'positions' => Position::query()->orderBy('id')->get(),
            'roster' => $this->roster($storeId, $date),
            // What the publish button is about to send, so the count is on the
            // button rather than a surprise after pressing it.
            'publishable' => $this->publisher->pendingInRange($storeId, $date, $date)->count(),
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
     * Read the week's punches from TCP the first time the actual tab lands on it.
     *
     * WHY THIS EXISTS. The tab rendered whatever had already been pulled, and
     * nothing pulled unless somebody found the "Pull the week's actual hours"
     * button and pressed it. So a store nobody had pressed it for showed an
     * empty grid that was indistinguishable from "nobody worked" — the punches
     * were sitting in TCP the whole time. Picking a week and pressing Go now
     * fetches it, which is what pressing Go looks like it should do.
     *
     * ON CHANGE, NOT ON RENDER, and keyed on store AND week — the same bargain
     * pullEmployeesOnStoreChange() strikes, for the same reason. Every approve,
     * correct and delete on this tab redirects back here, and a vendor round
     * trip on each would put TCP's latency in front of every click. Stepping
     * through a month costs four calls; clicking about within one week costs
     * none.
     *
     * NOTHING HERE CAN BREAK THE PAGE. The pull is a convenience; the hours
     * already in the table are not. Any failure degrades to a message and the
     * grid renders exactly as it would have.
     *
     * The button stays. This runs once per store-range, and re-pulling on demand
     * is free — the upsert is keyed on tcp_segment_id — so the way to get a
     * punch somebody made in the last minute is still to ask for it.
     *
     * Both boards call this. The key carries the whole range rather than just
     * its start, so a day board on Monday and a week board starting Monday are
     * two different pulls — otherwise opening the day first would convince the
     * week it had already fetched its other six days.
     */
    private function pullSegmentsOnRangeChange(Request $request, int $storeId, string $from, string $to): void
    {
        $key = $storeId.':'.$from.':'.$to;

        if (! $request->hasSession() || $request->session()->get(self::SEGMENT_PULL_KEY) === $key) {
            return;
        }

        // Recorded BEFORE the call: a week whose pull fails must not retry on
        // every subsequent render of the same grid.
        $request->session()->put(self::SEGMENT_PULL_KEY, $key);

        try {
            $result = $this->segmentSync->syncRange($from, $to, $storeId);
        } catch (Throwable $e) {
            $this->flashNow('err', 'Could not read this week\'s hours from TCP — '
                .class_basename($e).': '.$e->getMessage());

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

        $view = $request->query('view') === 'actual' ? 'actual' : 'planned';

        // Monday-first. startOfWeek() honours the app locale, which is not a
        // decision this screen should inherit silently.
        $start = CarbonImmutable::parse($anchor)->startOfWeek(CarbonInterface::MONDAY);
        $days = collect(range(0, 6))->map(fn (int $i): string => $start->addDays($i)->toDateString());

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
        if ($view === 'actual') {
            $this->pullSegmentsOnRangeChange($request, $storeId, $days->first(), $days->last());
        }

        $segments = WorkSegment::query()
            ->with(['employee', 'position'])
            ->forStoreBetween($storeId, $days->first(), $days->last())
            ->get();

        $roster = $this->roster($storeId, $days->first());

        return view('board.week', [
            'stores' => $stores,
            'storeId' => $storeId,
            'weekStart' => $start->toDateString(),
            'days' => $days->all(),
            'view' => $view,
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
            'rows' => $view === 'actual' ? $this->rowsForActual($roster, $segments, $days->first()) : $roster,
            'positions' => Position::query()->orderBy('id')->get(),
            'costs' => $this->costs->estimateFor($shifts, $storeId, null),
            'actuals' => $this->costs->actualFor($segments, $storeId),
            'publishable' => $this->publisher->pendingInRange($storeId, $days->first(), $days->last())->count(),
            'timezone' => $this->businessDay->timezoneFor($storeId),
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
     * @param  \Illuminate\Support\Collection<int, WorkSegment>  $segments
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
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

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
    public function unpublishShift(Request $request, Shift $shift): RedirectResponse
    {
        return $this->attempt(
            $request,
            fn () => $this->publisher->unpublish($shift),
            "Shift #{$shift->id} unpublished and editable. It is still live in Humanity — "
                .'re-publish to send the change as a PUT.',
        );
    }

    public function storeShift(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
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

        return $this->attempt($request, fn () => $this->shifts->create([
            'store_id' => (int) $data['store_id'],
            'employee_id' => $data['employee_id'] ?? null,
            'position_id' => $data['position_id'] ?? null,
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

        return $this->attempt($request, function () use ($shift, $data, $endDate) {
            return $this->shifts->update($shift, [
                'store_id' => $shift->store_id,
                'employee_id' => $data['employee_id'] ?? null,
                'position_id' => $data['position_id'] ?? null,
                'start_at_local' => "{$data['date']} {$data['start']}:00",
                'end_at_local' => "{$endDate} {$data['end']}:00",
                'notes' => $data['notes'] ?? null,
            ]);
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
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time_in' => ['required', 'date_format:H:i'],
            // A clock-out BEFORE the clock-in is a punch that ran past midnight
            // and rolls forward. One EQUAL to it is a typo, not a 24-hour shift.
            'time_out' => ['nullable', 'date_format:H:i', 'different:time_in'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $outDate = ($data['time_out'] ?? null) !== null && $data['time_out'] < $data['time_in']
            ? now()->parse($data['date'])->addDay()->toDateString()
            : $data['date'];

        $open = ($data['time_out'] ?? null) === null;

        return $this->attempt($request, fn () => $this->segments->create([
            'store_id' => (int) $data['store_id'],
            'employee_id' => (int) $data['employee_id'],
            'position_id' => $data['position_id'] ?? null,
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

        return $this->attempt(
            $request,
            fn () => $this->shifts->delete($shift, $rule),
            "Shift #{$shift->id} soft-deleted (rule: {$rule})."
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
