<?php

namespace App\Services\Scheduling;

use App\Enums\MatchSource;
use App\Enums\SegmentOrigin;
use App\Enums\TcpSyncState;
use App\Exceptions\SchedulingException;
use App\Jobs\PushWorkSegmentToTcp;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The manager's side of actual hours: approve them, correct them, or enter the
 * ones nobody punched.
 *
 * TIME CONTRACT, as everywhere else: time_in / time_out are UTC instants. A
 * caller holding store-local wall clock passes time_in_local / time_out_local
 * to create(), or converts with BusinessDay::combine() before correctTimes().
 *
 * business_date is always re-derived from time_in, never accepted from the
 * caller, because it is the answer to a timezone question and the day close
 * groups on it.
 */
class WorkSegmentService
{
    private const CREATABLE = [
        'employee_id',
        'store_id',
        'position_id',
        'break_minutes',
        'cost_code_name',
        'labor_code',
        'notes',
        'employee_approval',
    ];

    public function __construct(
        private readonly BusinessDay $businessDay,
        private readonly ReconciliationService $reconciliation,
    ) {}

    /**
     * The "forgot to clock in" workflow. origin = manual_create, and
     * tcp_segment_id stays NULL until a POST to TCP succeeds — a failed push
     * must leave visible hours behind, not lose them.
     *
     * Passing shift_id explicitly is a human insisting on a pairing, so it is
     * recorded as match_source = manual and the matcher leaves it alone.
     */
    public function create(array $attributes): WorkSegment
    {
        $storeId = $this->required($attributes, 'store_id');
        $this->required($attributes, 'employee_id');

        $timeIn = $this->resolveInstant($storeId, $attributes, 'time_in', null);
        $timeOut = $this->resolveInstant($storeId, $attributes, 'time_out', null);

        if ($timeIn === null) {
            throw new SchedulingException('A work segment needs a time_in.');
        }

        if ($timeOut !== null && $timeOut->lessThanOrEqualTo($timeIn)) {
            throw new SchedulingException('A work segment must clock out after it clocks in.', [
                'time_in' => $timeIn->toIso8601String(),
                'time_out' => $timeOut->toIso8601String(),
            ]);
        }

        $this->refuseOverlap((int) $attributes['employee_id'], $storeId, $timeIn, $timeOut);

        $manualShiftId = $attributes['shift_id'] ?? null;
        $breakMinutes = (int) ($attributes['break_minutes'] ?? 0);

        return DB::transaction(function () use ($attributes, $storeId, $timeIn, $timeOut, $breakMinutes, $manualShiftId): WorkSegment {
            $segment = WorkSegment::query()->create(array_merge(
                array_intersect_key($attributes, array_flip(self::CREATABLE)),
                [
                    'store_id' => $storeId,
                    'time_in' => $timeIn,
                    'time_out' => $timeOut,
                    'break_minutes' => $breakMinutes,
                    'business_date' => $this->businessDay->businessDate($storeId, $timeIn),
                    'hours' => $this->hoursBetween($timeIn, $timeOut, $breakMinutes),
                    'origin' => SegmentOrigin::ManualCreate,
                    'manager_approval' => false,
                    'shift_id' => $manualShiftId,
                    'match_source' => $manualShiftId === null ? MatchSource::Unmatched : MatchSource::Manual,
                    // Ours, and TCP has never seen it. POST /worksegments.
                    'tcp_sync_state' => TcpSyncState::Pending,
                ],
            ));

            $this->reconciliation->match($segment);
            $this->pushToTcp($segment);

            return $segment;
        });
    }

    /**
     * An open punch has no hours to approve.
     *
     * Refusing it here is what keeps the board's two outstanding categories from
     * collapsing into one: an "approved" open punch would clear itself off the
     * unapproved list while somebody's time was still missing. That mattered
     * when a day close was gated on it and it still matters now that nothing is
     * — the list is what a manager reads to know the day is settled.
     */
    public function approve(WorkSegment $segment, ?int $userId = null): WorkSegment
    {
        if ($segment->time_out === null) {
            throw new SchedulingException('An open punch cannot be approved: it has no hours yet.', [
                'work_segment_id' => $segment->id,
                'employee_id' => $segment->employee_id,
            ]);
        }

        return DB::transaction(function () use ($segment, $userId): WorkSegment {
            $segment->forceFill([
                'manager_approval' => true,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'tcp_sync_state' => TcpSyncState::Pending,
            ])->save();

            // "Approving Hours ... PUT /worksegments/{id}". An approval that
            // never reaches TCP means payroll pays from a number the timeclock
            // does not agree with.
            $this->pushToTcp($segment);

            return $segment;
        });
    }

    /**
     * The Change Shift workflow.
     *
     * A correction CLEARS manager_approval unless $reapprove is explicitly
     * true, because otherwise a segment stays approved for hours nobody
     * reviewed. Who corrected it and when are stamped either way.
     *
     * hours is recomputed here even though the column normally carries TCP's
     * own number: a correction is exactly the case where a human overrode TCP,
     * and leaving the stale figure would have the day close sign off hours that
     * no longer match the times on the row.
     *
     * A null $timeIn or $timeOut means "leave it alone" — this cannot re-open a
     * closed punch.
     */
    public function correctTimes(
        WorkSegment $segment,
        CarbonInterface|string|null $timeIn = null,
        CarbonInterface|string|null $timeOut = null,
        bool $reapprove = false,
        ?int $userId = null,
    ): WorkSegment {
        if ($timeIn === null && $timeOut === null) {
            return $segment;
        }

        $newIn = $timeIn === null ? CarbonImmutable::instance($segment->time_in) : $this->toUtc($timeIn);
        $newOut = $timeOut === null
            ? ($segment->time_out === null ? null : CarbonImmutable::instance($segment->time_out))
            : $this->toUtc($timeOut);

        if ($newOut !== null && $newOut->lessThanOrEqualTo($newIn)) {
            throw new SchedulingException('A corrected segment must clock out after it clocks in.', [
                'work_segment_id' => $segment->id,
                'time_in' => $newIn->toIso8601String(),
                'time_out' => $newOut->toIso8601String(),
            ]);
        }

        // A correction can walk a punch onto one of its neighbours just as easily
        // as a hand-entered one can be placed there, so the same guard applies —
        // excluding this segment, which of course overlaps itself.
        $this->refuseOverlap(
            (int) $segment->employee_id,
            (int) $segment->store_id,
            $newIn,
            $newOut,
            (int) $segment->id,
        );

        return DB::transaction(function () use ($segment, $newIn, $newOut, $reapprove, $userId): WorkSegment {
            $storeId = (int) $segment->store_id;
            $breakMinutes = (int) $segment->break_minutes;

            $segment->forceFill([
                'time_in' => $newIn,
                'time_out' => $newOut,
                'business_date' => $this->businessDay->businessDate($storeId, $newIn),
                'hours' => $this->hoursBetween($newIn, $newOut, $breakMinutes),
                'times_corrected_at' => now(),
                'times_corrected_by_user_id' => $userId,
                'manager_approval' => $reapprove,
                'approved_by_user_id' => $reapprove ? $userId : null,
                'approved_at' => $reapprove ? now() : null,
            ])->save();

            // The times moved, so the shift this belongs to may have too.
            $this->reconciliation->match($segment);

            // "Change Shift ... PUT /worksegments/{id} but with parameters of
            // timeIn and timeOut."
            $segment->forceFill(['tcp_sync_state' => TcpSyncState::Pending])->save();

            $this->pushToTcp($segment);

            return $segment;
        });
    }

    /**
     * REFUSE a punch that would put one person in two places at once.
     *
     * BLOCKS, it does not warn, and that is the opposite of how ShiftService
     * treats an overlapping SHIFT. The asymmetry is the point:
     *
     *   A shift is a PLAN. Double-booking somebody is sometimes what a manager
     *       means — cover is being arranged, the person has agreed to it — so
     *       ShiftService::conflicts() surfaces it and saves anyway.
     *   A segment is a RECORD OF FACT. Nobody worked two overlapping stretches,
     *       so an overlap is not a decision, it is a mistake — and it is one
     *       that silently pays them twice, because the day close and the labour
     *       cost both sum `hours` across segments.
     *
     * ONLY THE MANUAL PATHS. WorkSegmentSyncService writes its own rows straight
     * through WorkSegment::create, deliberately bypassing this: TCP is the source
     * of truth for punches, and if a real timeclock reports overlapping ones then
     * that is what happened and refusing to store it would lose the evidence.
     * This guards what a human types on the board.
     *
     * ACROSS STORES, NOT JUST THIS ONE. A person covering at another store still
     * cannot be in both, and the estate does move people around — so the query
     * is keyed on the employee alone and the store is named in the message.
     *
     * @throws SchedulingException
     */
    private function refuseOverlap(
        int $employeeId,
        int $storeId,
        CarbonImmutable $timeIn,
        ?CarbonImmutable $timeOut,
        ?int $excludeSegmentId = null,
    ): void {
        // The store only decides which calendar day bounds the scan. The overlap
        // test itself compares UTC instants, so a punch filed at another store
        // under a different timezone is still caught.
        $day = CarbonImmutable::parse($this->businessDay->businessDate($storeId, $timeIn));

        $clash = WorkSegment::query()
            ->where('employee_id', $employeeId)
            ->when(
                $excludeSegmentId !== null,
                fn ($query) => $query->whereKeyNot($excludeSegmentId),
            )
            // A day either side keeps the (employee_id, business_date) index in
            // play while still catching an overnight neighbour — the same bound
            // ShiftService::overlapWarnings() uses.
            ->whereBetween('business_date', [
                $day->subDay()->toDateString(),
                $day->addDay()->toDateString(),
            ])
            // AN OPEN PUNCH HAS NO END, so it is treated as running forever:
            // somebody still clocked in cannot start a second punch, and that is
            // the most common way this gets hit.
            ->where(function ($query) use ($timeIn): void {
                $query->whereNull('time_out')->orWhere('time_out', '>', $timeIn);
            })
            // A new OPEN punch runs forever too, so it clashes with anything that
            // ends after it starts and there is no upper bound to apply.
            ->when(
                $timeOut !== null,
                fn ($query) => $query->where('time_in', '<', $timeOut),
            )
            ->orderBy('time_in')
            ->first();

        if ($clash === null) {
            return;
        }

        $storeId = $clash->store_id === null ? null : (int) $clash->store_id;
        $local = fn (?CarbonInterface $at): string => $at === null
            ? 'still in'
            : $this->businessDay->toLocal($storeId, $at)->format('H:i');

        $isDuplicate = $clash->time_in !== null
            && $clash->time_in->equalTo($timeIn)
            && (($clash->time_out === null && $timeOut === null)
                || ($clash->time_out !== null && $timeOut !== null && $clash->time_out->equalTo($timeOut)));

        throw new SchedulingException(
            $isDuplicate
                ? sprintf(
                    'This is the same punch as work segment #%d — %s, %s to %s at store %s. It was not '
                    .'created, because two identical punches would pay these hours twice.',
                    $clash->id,
                    $clash->business_date?->toDateString() ?? '?',
                    $local($clash->time_in),
                    $local($clash->time_out),
                    $clash->store_id ?? '?',
                )
                : sprintf(
                    'This punch overlaps work segment #%d — %s, %s to %s at store %s. It was not created, '
                    .'because nobody can work two stretches at once and the day close would sum both. '
                    .'%s',
                    $clash->id,
                    $clash->business_date?->toDateString() ?? '?',
                    $local($clash->time_in),
                    $local($clash->time_out),
                    $clash->store_id ?? '?',
                    $clash->time_out === null
                        ? 'That punch is still open — clock it out first, then enter this one.'
                        : 'Correct the existing punch instead, or delete it if it is wrong.',
                ),
            [
                'employee_id' => $employeeId,
                'work_segment_id' => $clash->id,
                'time_in' => $timeIn->toIso8601String(),
                'time_out' => $timeOut?->toIso8601String(),
                'duplicate' => $isDuplicate,
            ],
        );
    }

    /** Soft delete. The hours stay recoverable; a punch is evidence. */
    public function delete(WorkSegment $segment): bool
    {
        return (bool) DB::transaction(function () use ($segment): bool {
            $deleted = (bool) $segment->delete();

            // "Deleting Shifts ... DEL /worksegments/{id}". The job reads the
            // row withTrashed and sends the delete; a row TCP never saw is a
            // no-op there.
            if ($deleted) {
                $this->pushToTcp($segment);
            }

            return $deleted;
        });
    }

    /**
     * Queue the write-back, AFTER the surrounding transaction commits.
     *
     * afterCommit matters: dispatched inline, a worker fast enough to pick the
     * job up before the commit would read the pre-change row — or no row at all
     * on a create — and push a stale version of it to TCP.
     */
    private function pushToTcp(WorkSegment $segment): void
    {
        PushWorkSegmentToTcp::dispatch((int) $segment->id)->afterCommit();
    }

    /** Null when the punch is still open — there is nothing to total yet. */
    private function hoursBetween(CarbonImmutable $timeIn, ?CarbonImmutable $timeOut, int $breakMinutes): ?float
    {
        if ($timeOut === null) {
            return null;
        }

        $minutes = abs($timeIn->diffInMinutes($timeOut)) - $breakMinutes;

        return round(max($minutes, 0) / 60, 2);
    }

    private function resolveInstant(
        int $storeId,
        array $attributes,
        string $key,
        ?CarbonImmutable $fallback,
    ): ?CarbonImmutable {
        if (($attributes[$key.'_local'] ?? null) !== null) {
            return $this->businessDay->toUtc($storeId, $attributes[$key.'_local']);
        }

        $value = $attributes[$key] ?? null;

        return $value === null ? $fallback : $this->toUtc($value);
    }

    private function toUtc(CarbonInterface|string $value): CarbonImmutable
    {
        return $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)->utc()
            : CarbonImmutable::parse($value, 'UTC');
    }

    private function required(array $attributes, string $key): int
    {
        if (($attributes[$key] ?? null) === null) {
            throw new SchedulingException("A work segment needs a {$key}.");
        }

        return (int) $attributes[$key];
    }
}
