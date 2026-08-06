<?php

namespace App\Services\Scheduling;

use App\Enums\AvailabilityCheck;
use App\Enums\PublishState;
use App\Exceptions\SchedulingException;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Shift;
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
        'unpaid_break_minutes',
        'notes',
    ];

    /** publish_* and humanity_shift_id belong to the publisher, not to callers. */
    private const CREATABLE = [
        'employee_id',
        'store_id',
        'position_id',
        'unpaid_break_minutes',
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
        'unpaid_break_minutes',
        'notes',
        'repeat_rule',
        'repeat_until',
    ];

    public function __construct(
        private readonly BusinessDay $businessDay,
        private readonly AvailabilityChecker $availability,
    ) {}

    public function create(array $attributes): Shift
    {
        $storeId = $this->requireStoreId($attributes['store_id'] ?? null);
        [$startUtc, $endUtc] = $this->resolveInstants($storeId, $attributes, null);

        $employeeId = isset($attributes['employee_id']) ? (int) $attributes['employee_id'] : null;

        $payload = array_merge($this->payload($attributes, self::CREATABLE), [
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'start_at' => $startUtc,
            'end_at' => $endUtc,
            'business_date' => $attributes['business_date'] ?? $this->businessDay->businessDate($storeId, $startUtc),
            // A new shift is always a draft. Nothing has been told about it yet.
            'publish_state' => PublishState::Draft,
            'availability_check' => $this->availabilityFor($employeeId, $storeId, $startUtc, $endUtc),
        ]);

        return DB::transaction(fn (): Shift => Shift::query()->create($payload));
    }

    public function update(Shift $shift, array $attributes): Shift
    {
        $storeId = $this->requireStoreId($attributes['store_id'] ?? $shift->store_id);
        [$startUtc, $endUtc] = $this->resolveInstants($storeId, $attributes, $shift);

        return DB::transaction(function () use ($shift, $attributes, $storeId, $startUtc, $endUtc): Shift {
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

            $shift->save();

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
     * The gap between the parts is NOT unpaid_break_minutes — a break is unpaid
     * time inside one block, a split gap is the space between two — so part 2
     * starts with no break and part 1 keeps its own.
     *
     * $secondStart / $secondEnd are UTC instants. Returns the NEW part.
     */
    public function split(Shift $shift, CarbonInterface $secondStart, CarbonInterface $secondEnd): Shift
    {
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

            return Shift::query()->create([
                'employee_id' => $employeeId,
                'store_id' => $storeId,
                'position_id' => $shift->position_id,
                'business_date' => $this->businessDay->businessDate($storeId, $startUtc),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'unpaid_break_minutes' => 0,
                'notes' => $shift->notes,
                'repeat_rule' => $shift->repeat_rule,
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
        });
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
