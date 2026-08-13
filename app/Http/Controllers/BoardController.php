<?php

namespace App\Http\Controllers;

use App\Enums\PublishState;
use App\Enums\RequestDecision;
use App\Exceptions\SchedulingException;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkSegment;
use App\Services\Scheduling\BoardService;
use App\Services\Scheduling\DayCloseService;
use App\Services\Scheduling\EmployeeRequestService;
use App\Services\Scheduling\LaborCostEstimator;
use App\Services\Scheduling\SchedulePublisher;
use App\Services\Scheduling\ShiftService;
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
        private readonly DayCloseService $dayClose,
        private readonly LaborCostEstimator $costs,
        private readonly SchedulePublisher $publisher,
        private readonly WorkSegmentSyncService $segmentSync,
        private readonly TcpEmployeeReader $tcpEmployees,
        private readonly BusinessDay $businessDay,
        private readonly ActingUser $actingUser,
    ) {}

    public function index(Request $request): View
    {
        $stores = Store::query()->orderBy('id')->get();
        $storeId = (int) ($request->query('store') ?: $stores->first()?->id ?: DemoSeeder::STORE_ID);

        $this->pullEmployeesOnStoreChange($request, $storeId);

        $date = (string) ($request->query('date')
            ?: $this->businessDay->toLocal($storeId, now())->toDateString());

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

        if (in_array('store_has_no_tcp_location', $reasons, true)) {
            $this->flashNow('err', 'This store has no TCP location id, so its roster could not be read.');

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
     * The week grid: seven days across, one row per employee.
     *
     * The day board is for the ACTUAL side — punches, approvals, the close,
     * all of which are inherently about one date. This is for building the
     * plan, which people do a week at a time and by dragging.
     */
    public function week(Request $request): View
    {
        $stores = Store::query()->orderBy('id')->get();
        $storeId = (int) ($request->query('store') ?: $stores->first()?->id ?: DemoSeeder::STORE_ID);

        $anchor = (string) ($request->query('week')
            ?: $this->businessDay->toLocal($storeId, now())->toDateString());

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

        return view('board.week', [
            'stores' => $stores,
            'storeId' => $storeId,
            'weekStart' => $start->toDateString(),
            'days' => $days->all(),
            'shifts' => $shifts,
            'byCell' => $shifts->groupBy([
                fn (Shift $s): string => (string) ($s->employee_id ?? 'open'),
                fn (Shift $s): string => $this->dateOf($s->business_date),
            ]),
            'roster' => $this->roster($storeId, $days->first()),
            'positions' => Position::query()->orderBy('id')->get(),
            'costs' => $this->costs->estimateFor($shifts, $storeId, null),
            'publishable' => $this->publisher->pendingInRange($storeId, $days->first(), $days->last())->count(),
            'timezone' => $this->businessDay->timezoneFor($storeId),
        ]);
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
        ]);

        try {
            $result = $this->segmentSync->syncDate($data['date'], (int) $data['store_id']);
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
            ? 'TCP returned no punches for '.$data['date'].'.'
            : 'Pulled '.$result['fetched'].' from TCP for '.$data['date']
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

    public function closeDay(Request $request): RedirectResponse
    {
        try {
            $result = $this->dayClose->close(
                (int) $request->input('store_id'),
                (string) $request->input('date'),
                $this->actingUser->id(),
            );

            return back()->with('ok', 'Day closed at '.$result['closed_at'].'.');
        } catch (SchedulingException $e) {
            return back()->with('err', $e->getMessage());
        } catch (Throwable $e) {
            return back()->with('err', $e->getMessage());
        }
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
            ->where('primary_store_id', $storeId)
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
