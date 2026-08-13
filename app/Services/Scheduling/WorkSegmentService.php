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
     * An open punch has no hours to approve. Refusing it here is what keeps
     * DayCloseService's two blocker categories from collapsing into one — an
     * "approved" open punch would let a day close over somebody's missing time.
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
