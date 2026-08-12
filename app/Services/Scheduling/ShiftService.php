<?php

namespace App\Services\Scheduling;

use App\Enums\ActivityAction;
use App\Enums\AvailabilityCheck;
use App\Enums\PublishState;
use App\Exceptions\SchedulingException;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Shift;
use App\Services\ActivityLogger;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Building the schedule: create, edit, delete, split, and warn.
 *
 * Everything here is local until a publish run picks it up, so nothing in this
 * class talks to Humanity. What it does do is keep publish_state and
 * payload_fingerprint honest, because those are how the publisher knows a shift
 * changed under it.
 *
 * TIME CONTRACT. start_at / end_at are UTC instants (a string, or a Carbon).
 * A caller holding store-local wall clock passes start_at_local / end_at_local
 * instead and BusinessDay converts them. business_date is derived from the
 * start unless the caller states it.
 */
class ShiftService
{
    /**
     * The fields that end up in a Humanity payload. Touch one and the
     * fingerprint is void, so the next publish re-sends the shift instead of
     * skipping it as unchanged.
     */
    private const HUMANITY_VISIBLE = [
        'employee_id',
        'store_id',
        'position_id',
        'business_date',
        'start_at',
        'end_at',
        'notes',
    ];

    /** publish_* and humanity_shift_id belong to the publisher, not to callers. */
    private const CREATABLE = [
        'employee_id',
        'store_id',
        'position_id',
        'notes',
        'repeat_rule',
        'repeat_until',
        'series_id',
        'split_group_id',
        'split_part',
        'created_by_user_id',
    ];

    private const UPDATABLE = [
        'employee_id',
        'store_id',
        'position_id',
        'notes',
        'repeat_rule',
        'repeat_until',
    ];

    public function __construct(
        private readonly BusinessDay $businessDay,
        private readonly AvailabilityChecker $availability,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  ActivityAction  $as  what this creation IS — copy() passes Copied
     *                              so one drag writes one line, not two
     * @param  array<string, mixed>  $context  recorded alongside it
     */
    public function create(
        array $attributes,
        ActivityAction $as = ActivityAction::Created,
        array $context = [],
    ): Shift {
        $storeId = $this->requireStoreId($attributes['store_id'] ?? null);
        [$startUtc, $endUtc] = $this->resolveInstants($storeId, $attributes, null);

        $employeeId = isset($attributes['employee_id']) ? (int) $attributes['employee_id'] : null;

        $payload = array_merge($this->payload($attributes, self::CREATABLE), [
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'start_at' => $startUtc,
            'end_at' => $endUtc,
            'business_date' => $attributes['business_date'] ?? $this->businessDay->businessDate($storeId, $startUtc),
            // Set explicitly rather than leaning on the column default. Left out
            // of the insert, the row gets 'none' but the model handed back still
            // holds null — and anything copying from that model (split() does)
            // then writes that null over the default and hits the NOT NULL.
            'repeat_rule' => $attributes['repeat_rule'] ?? 'none',
            // A new shift is always a draft. Nothing has been told about it yet.
            'publish_state' => PublishState::Draft,
            'availability_check' => $this->availabilityFor($employeeId, $storeId, $startUtc, $endUtc),
        ]);

        $shift = DB::transaction(fn (): Shift => Shift::query()->create($payload));

        $this->activity->shift($shift, $as, [], $context);

        return $shift;
    }

    /**
     * @param  ?ActivityAction  $as  null suppresses the log entirely, for
     *                               callers like move() that record their own
     *                               higher-level action instead
     */
    public function update(Shift $shift, array $attributes, ?ActivityAction $as = ActivityAction::Updated): Shift
    {
        $this->refuseIfLocked($shift, 'edited');

        $storeId = $this->requireStoreId($attributes['store_id'] ?? $shift->store_id);
        [$startUtc, $endUtc] = $this->resolveInstants($storeId, $attributes, $shift);

        return DB::transaction(function () use ($shift, $attributes, $storeId, $startUtc, $endUtc, $as): Shift {
            $shift->fill(array_merge($this->payload($attributes, self::UPDATABLE), [
                'store_id' => $storeId,
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'business_date' => $attributes['business_date']
                    ?? $this->businessDay->businessDate($storeId, $startUtc),
            ]));

            // Void the fingerprint BEFORE saving, while the dirty set still
            // describes this edit. A shift already in Humanity keeps its
            // humanity_shift_id — the publisher updates it, it does not
            // duplicate it — but it must not be skipped as unchanged.
            if ($shift->isDirty(self::HUMANITY_VISIBLE)) {
                $shift->payload_fingerprint = null;
            }

            $employeeId = $shift->employee_id === null ? null : (int) $shift->employee_id;
            $shift->availability_check = $this->availabilityFor($employeeId, $storeId, $startUtc, $endUtc);

            // Captured BEFORE save(), while the dirty set still describes the
            // edit. Afterwards everything is clean and the diff is empty.
            $changes = $this->activity->diff($shift, [
                'employee_id', 'position_id', 'business_date',
                'start_at', 'end_at', 'notes',
            ]);

            $shift->save();

            if ($changes !== [] && $as !== null) {
                $this->activity->shift($shift, $as, $changes);
            }

            return $shift;
        });
    }

    /**
     * Soft delete, with Figure 25's series rules.
     *
     *   'following' — this occurrence and every later one. The default,
     *                 because it is the survivable mistake.
     *   'all'       — the entire series, past occurrences included. Explicit
     *                 only: it wipes history.
     *
     * A shift with no series_id ignores the rule and deletes only itself.
     *
     * @return int rows soft-deleted
     */
    public function delete(Shift $shift, string $rule = 'following'): int
    {
        if (! in_array($rule, ['following', 'all'], true)) {
            throw new SchedulingException(
                "Unknown delete rule '{$rule}'. Expected 'following' or 'all'.",
                ['shift_id' => $shift->id, 'rule' => $rule],
            );
        }

        return DB::transaction(function () use ($shift, $rule): int {
            $query = $shift->series_id === null
                ? Shift::query()->whereKey($shift->getKey())
                : Shift::query()->inSeries(
                    (string) $shift->series_id,
                    $rule === 'following' ? $this->dateString($shift->business_date) : null,
                );

            $deleted = (int) $query->delete();

            $this->activity->shift($shift, ActivityAction::Deleted, [], [
                'rule' => $rule,
                'rows_deleted' => $deleted,
                'series_id' => $shift->series_id,
            ]);

            // A mass soft delete leaves the passed model stale; say so in
            // memory so a caller's ->trashed() is not a lie.
            $shift->setAttribute($shift->getDeletedAtColumn(), $shift->freshTimestamp());
            $shift->syncOriginal();

            return $deleted;
        });
    }

    /**
     * Split one block into two, e.g. 11:00-14:00 and 17:00-21:00.
     *
     * TWO ROWS, never one row with a hole in it: a row maps 1:1 onto a Humanity
     * shift and onto the punches that reconcile against it, and keeping that
     * alignment means publishing and matching need no special case for splits.
     *
     * The gap between the parts is unpaid and is not a break. Nothing here
     * carries a break at all — that is TCP's number, on work_segments.
     *
     * $secondStart / $secondEnd are UTC instants. Returns the NEW part.
     */
    public function split(Shift $shift, CarbonInterface $secondStart, CarbonInterface $secondEnd): Shift
    {
        $this->refuseIfLocked($shift, 'split');

        $startUtc = CarbonImmutable::instance($secondStart)->utc();
        $endUtc = CarbonImmutable::instance($secondEnd)->utc();

        if ($endUtc->lessThanOrEqualTo($startUtc)) {
            throw new SchedulingException('The second part of a split must end after it starts.', [
                'shift_id' => $shift->id,
            ]);
        }

        if ($shift->end_at !== null && $startUtc->lessThan($shift->end_at)) {
            throw new SchedulingException(
                'The second part of a split must start after the first part ends.',
                ['shift_id' => $shift->id, 'first_part_end_at' => $shift->end_at->toIso8601String()],
            );
        }

        return DB::transaction(function () use ($shift, $startUtc, $endUtc): Shift {
            if ($shift->split_group_id === null) {
                $shift->split_group_id = (string) Str::ulid();
                $shift->split_part = 1;
                $shift->save();
            }

            // withTrashed so a deleted part cannot have its number handed out
            // twice inside one group.
            $nextPart = (int) Shift::query()
                ->withTrashed()
                ->where('split_group_id', $shift->split_group_id)
                ->max('split_part') + 1;

            $storeId = (int) $shift->store_id;
            $employeeId = $shift->employee_id === null ? null : (int) $shift->employee_id;

            $part = Shift::query()->create([
                'employee_id' => $employeeId,
                'store_id' => $storeId,
                'position_id' => $shift->position_id,
                'business_date' => $this->businessDay->businessDate($storeId, $startUtc),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'notes' => $shift->notes,
                'repeat_rule' => $shift->repeat_rule ?? 'none',
                'repeat_until' => $shift->repeat_until,
                'series_id' => $shift->series_id,
                'split_group_id' => $shift->split_group_id,
                'split_part' => $nextPart,
                // A row Humanity has never seen. It inherits none of part 1's
                // publish state; humanity_shift_id is UNIQUE and part 1's alone.
                'publish_state' => PublishState::Draft,
                // Each part is checked on its own. The gap is not work, so
                // nothing has to cover it.
                'availability_check' => $this->availabilityFor($employeeId, $storeId, $startUtc, $endUtc),
                'created_by_user_id' => $shift->created_by_user_id,
            ]);

            $this->activity->shift($part, ActivityAction::Split, [], [
                'split_group_id' => $shift->split_group_id,
                'split_part' => $part->split_part,
                'from_shift_id' => (int) $shift->id,
            ]);

            return $part;
        });
    }

    /**
     * Drop a shift on another day, another person, or both.
     *
     * The times of day are PRESERVED and the date is rebuilt around them: a
     * 17:00-21:00 shift dragged to Thursday is still 17:00-21:00. Carrying the
     * raw UTC instants across a DST boundary would silently shift a 17:00 shift
     * to 16:00, which is exactly the kind of error nobody notices until payroll.
     *
     * Composed from update(), which voids payload_fingerprint because
     * employee_id / business_date / start_at / end_at are all HUMANITY_VISIBLE.
     * A moved shift that is already published therefore re-sends on the next
     * publish run rather than being skipped as unchanged.
     */
    public function move(Shift $shift, ?string $businessDate = null, mixed $employeeId = false): Shift
    {
        $this->refuseIfLocked($shift, 'moved');
        $this->refuseIfWorked($shift, 'moved');

        $storeId = (int) $shift->store_id;
        $targetDate = $businessDate ?? $this->dateString($shift->business_date);

        [$startLocal, $endLocal] = $this->localClockTimes($shift);

        // The end rolls to the next day when the block crosses midnight, the
        // same rule the rest of the console uses.
        $endDate = $endLocal <= $startLocal
            ? CarbonImmutable::parse($targetDate)->addDay()->toDateString()
            : $targetDate;

        $attributes = [
            'store_id' => $storeId,
            'start_at_local' => "{$targetDate} {$startLocal}:00",
            'end_at_local' => "{$endDate} {$endLocal}:00",
            'business_date' => $targetDate,
        ];

        // false means "leave the employee alone"; null means "make it open".
        // A plain nullable int cannot express both.
        if ($employeeId !== false) {
            $attributes['employee_id'] = $employeeId === null ? null : (int) $employeeId;
        }

        $from = [
            'business_date' => $this->dateString($shift->business_date),
            'employee_id' => $shift->employee_id === null ? null : (int) $shift->employee_id,
        ];

        // null: one drag is one line. move() records the move below; letting
        // update() also log would put the same action in the trail twice.
        $moved = $this->update($shift, $attributes, null);

        $this->activity->shift($moved, ActivityAction::Moved, array_filter([
            'business_date' => $from['business_date'] === $this->dateString($moved->business_date)
                ? null
                : ['from' => $from['business_date'], 'to' => $this->dateString($moved->business_date)],
            'employee_id' => $from['employee_id'] === ($moved->employee_id === null ? null : (int) $moved->employee_id)
                ? null
                : ['from' => $from['employee_id'], 'to' => $moved->employee_id === null ? null : (int) $moved->employee_id],
        ]));

        return $moved;
    }

    /**
     * Duplicate a shift onto another day or person, leaving the original.
     *
     * series_id, split_group_id and split_part are explicitly NULLED. They are
     * in CREATABLE, so copying the source's attributes wholesale would enrol
     * the duplicate in the original's recurring series or split group — and a
     * later "delete following" on that series would then silently take the copy
     * with it.
     *
     * The copy is always a fresh draft: humanity_shift_id is unique and belongs
     * to the row that earned it.
     */
    public function copy(Shift $shift, ?string $businessDate = null, mixed $employeeId = false): Shift
    {
        $storeId = (int) $shift->store_id;
        $targetDate = $businessDate ?? $this->dateString($shift->business_date);

        [$startLocal, $endLocal] = $this->localClockTimes($shift);

        $endDate = $endLocal <= $startLocal
            ? CarbonImmutable::parse($targetDate)->addDay()->toDateString()
            : $targetDate;

        $copy = $this->create([
            'store_id' => $storeId,
            'employee_id' => $employeeId === false
                ? ($shift->employee_id === null ? null : (int) $shift->employee_id)
                : ($employeeId === null ? null : (int) $employeeId),
            'position_id' => $shift->position_id,
            'notes' => $shift->notes,
            'start_at_local' => "{$targetDate} {$startLocal}:00",
            'end_at_local' => "{$endDate} {$endLocal}:00",
            'business_date' => $targetDate,
            // Deliberately NOT carried over. See the docblock.
            'repeat_rule' => 'none',
            'repeat_until' => null,
            'series_id' => null,
            'split_group_id' => null,
            'split_part' => null,
            'created_by_user_id' => $shift->created_by_user_id,
        ], ActivityAction::Copied, [
            'copied_from_shift_id' => (int) $shift->id,
            'copied_from_business_date' => $this->dateString($shift->business_date),
        ]);

        return $copy;
    }

    /**
     * A published shift is locked. Unpublish it first.
     *
     * A POST to Humanity is live the instant it lands, so a published shift is
     * something employees are already planning their week around. Changing one
     * silently — and only telling Humanity at the next publish sweep — means
     * the roster on the wall and the roster in their phone disagree for as long
     * as that takes. Making the manager unpublish first turns that into a
     * deliberate act.
     *
     * Delete is deliberately NOT gated: cancelling a shift is urgent, it sends
     * its own DELETE to Humanity, and requiring two steps to call one off would
     * be friction in exactly the wrong place. Copy is not gated either — it
     * produces a fresh draft and leaves the published row alone.
     */
    private function refuseIfLocked(Shift $shift, string $verb): void
    {
        if (! $shift->publish_state?->isLocked()) {
            return;
        }

        throw new SchedulingException(
            "Shift #{$shift->id} is published and cannot be {$verb}. "
                .'Unpublish it first — it stays live in Humanity, and re-publishing sends the change as a PUT.',
            ['shift_id' => $shift->id, 'publish_state' => $shift->publish_state?->value],
        );
    }

    /**
     * A shift with hours worked against it cannot be dragged.
     *
     * Moving a plan to another day when the work already happened is
     * incoherent, and the alternative — silently unlinking work_segments.shift_id
     * — would destroy a reconciliation a human may have made by hand. Copying
     * is still fine: it leaves the original and its punches alone.
     */
    private function refuseIfWorked(Shift $shift, string $verb): void
    {
        $punches = $shift->workSegments()->count();

        if ($punches === 0) {
            return;
        }

        throw new SchedulingException(
            "Shift #{$shift->id} cannot be {$verb}: {$punches} punch(es) are already reconciled against it. "
                .'Copy it instead, or delete the punches first.',
            ['shift_id' => $shift->id, 'work_segments' => $punches],
        );
    }

    /**
     * Store-local HH:MM for a shift's two ends.
     *
     * @return array{0: string, 1: string}
     */
    private function localClockTimes(Shift $shift): array
    {
        $storeId = $shift->store_id === null ? null : (int) $shift->store_id;

        return [
            $this->businessDay->toLocal($storeId, $shift->start_at)->format('H:i'),
            $this->businessDay->toLocal($storeId, $shift->end_at)->format('H:i'),
        ];
    }

    /**
     * Everything wrong with this shift that a human should see before saving.
     *
     * WARNS, NEVER BLOCKS. None of these refuse a save — a manager who knows
     * the person is coming in anyway must be able to place the shift.
     *
     * Three checks, and they are here rather than in the schema because MySQL
     * can express none of them: an overlap is a range predicate, time off lives
     * in another table, and an age is a question about a date.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conflicts(Shift $shift): array
    {
        if ($shift->employee_id === null || $shift->start_at === null || $shift->end_at === null) {
            return [];
        }

        $employeeId = (int) $shift->employee_id;
        $storeId = $shift->store_id === null ? null : (int) $shift->store_id;
        $businessDate = $this->dateString($shift->business_date);

        return array_merge(
            $this->overlapWarnings($shift, $employeeId, $storeId, $businessDate),
            $this->timeOffWarnings($employeeId, $businessDate),
            $this->minorWarnings($shift, $employeeId, $businessDate),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function overlapWarnings(Shift $shift, int $employeeId, ?int $storeId, string $businessDate): array
    {
        $day = CarbonImmutable::parse($businessDate);

        $overlapping = Shift::query()
            ->where('employee_id', $employeeId)
            // A day either side keeps the (employee_id, business_date) index in
            // play while still catching an overnight neighbour.
            ->whereBetween('business_date', [$day->subDay()->toDateString(), $day->addDay()->toDateString()])
            ->where('start_at', '<', $shift->end_at)
            ->where('end_at', '>', $shift->start_at)
            ->when($shift->exists, fn ($query) => $query->whereKeyNot($shift->getKey()))
            ->orderBy('start_at')
            ->get();

        return $overlapping->map(fn (Shift $other): array => [
            'type' => 'overlapping_shift',
            'severity' => 'warning',
            'shift_id' => $other->id,
            'store_id' => $other->store_id,
            'business_date' => $this->dateString($other->business_date),
            'start_at' => $other->start_at?->toIso8601String(),
            'end_at' => $other->end_at?->toIso8601String(),
            'message' => sprintf(
                'Overlaps shift #%d on %s, %s to %s local.',
                $other->id,
                $this->dateString($other->business_date),
                $this->businessDay->toLocal($storeId, $other->start_at)->format('H:i'),
                $this->businessDay->toLocal($storeId, $other->end_at)->format('H:i'),
            ),
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function timeOffWarnings(int $employeeId, string $businessDate): array
    {
        return EmployeeRequest::query()
            ->approvedTimeOffCovering($employeeId, $businessDate)
            ->get()
            ->map(fn (EmployeeRequest $request): array => [
                'type' => 'approved_time_off',
                'severity' => 'warning',
                'employee_request_id' => $request->id,
                'start_date' => $this->dateString($request->start_date),
                'end_date' => $this->dateString($request->end_date),
                'message' => sprintf(
                    'Approved time off covers %s (request #%d, %s to %s).',
                    $businessDate,
                    $request->id,
                    $this->dateString($request->start_date),
                    $this->dateString($request->end_date),
                ),
            ])->all();
    }

    /**
     * Minor labour rules turn on the employee's age ON THE SHIFT DATE, which is
     * why birth_date is projected and an age column is not. The age below is
     * computed for the message and thrown away.
     *
     * @return array<int, array<string, mixed>>
     */
    private function minorWarnings(Shift $shift, int $employeeId, string $businessDate): array
    {
        $employee = $shift->relationLoaded('employee')
            ? $shift->employee
            : Employee::query()->find($employeeId);

        if ($employee?->birth_date === null) {
            return [];
        }

        $birth = CarbonImmutable::instance($employee->birth_date);
        $onDate = CarbonImmutable::parse($businessDate);

        if ($birth->addYears(18)->lessThanOrEqualTo($onDate)) {
            return [];
        }

        return [[
            'type' => 'minor',
            'severity' => 'warning',
            'employee_id' => $employeeId,
            'birth_date' => $birth->toDateString(),
            'age_on_shift_date' => (int) floor(abs($birth->diffInYears($onDate))),
            'message' => sprintf(
                '%s is under 18 on %s — minor labour rules may apply.',
                $employee->fullName(),
                $businessDate,
            ),
        ]];
    }

    private function availabilityFor(
        ?int $employeeId,
        int $storeId,
        CarbonInterface $startUtc,
        CarbonInterface $endUtc,
    ): AvailabilityCheck {
        if ($employeeId === null) {
            return AvailabilityCheck::Unknown;
        }

        return $this->availability->check(
            Employee::query()->find($employeeId),
            $startUtc,
            $endUtc,
            $storeId,
        );
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function resolveInstants(int $storeId, array $attributes, ?Shift $shift): array
    {
        $start = $this->resolveInstant($storeId, $attributes, 'start_at', $shift?->start_at);
        $end = $this->resolveInstant($storeId, $attributes, 'end_at', $shift?->end_at);

        if ($start === null || $end === null) {
            throw new SchedulingException('A shift needs both a start_at and an end_at.');
        }

        // MySQL enforces this with a CHECK; the SQLite dev connection cannot,
        // so the application has to, or dev and production disagree.
        if ($end->lessThanOrEqualTo($start)) {
            throw new SchedulingException('A shift must end after it starts.', [
                'start_at' => $start->toIso8601String(),
                'end_at' => $end->toIso8601String(),
            ]);
        }

        return [$start, $end];
    }

    private function resolveInstant(
        int $storeId,
        array $attributes,
        string $key,
        CarbonInterface|string|null $fallback,
    ): ?CarbonImmutable {
        // Store-local wall clock wins when given: it is the more specific
        // statement of intent, and it is what a scheduling UI actually holds.
        if (($attributes[$key.'_local'] ?? null) !== null) {
            return $this->businessDay->toUtc($storeId, $attributes[$key.'_local']);
        }

        $value = $attributes[$key] ?? $fallback;

        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)->utc()
            : CarbonImmutable::parse($value, 'UTC');
    }

    private function requireStoreId(mixed $storeId): int
    {
        if ($storeId === null || $storeId === '') {
            throw new SchedulingException('A shift needs a store_id: it is what decides the timezone.');
        }

        return (int) $storeId;
    }

    /** @return array<string, mixed> */
    private function payload(array $attributes, array $allowed): array
    {
        return array_intersect_key($attributes, array_flip($allowed));
    }

    private function dateString(CarbonInterface|string|null $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
    }
}
