<?php

namespace App\Services\Scheduling;

use App\Models\Shift;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The Figure 12/13 board: one store, one day, plan against reality.
 *
 * Four views of the same two lists:
 *   scheduled            — what we planned
 *   present              — what actually happened
 *   scheduled_absent     — planned, nobody punched
 *   present_unscheduled  — punched, nothing planned
 *
 * ONE indexed query per side. The two deltas are set arithmetic over the rows
 * already in memory, not two more round trips, and the cost estimate is handed
 * the shifts it needs rather than fetching them again.
 */
class BoardService
{
    public function __construct(
        private readonly BusinessDay $businessDay,
        private readonly DayCloseService $dayClose,
        private readonly LaborCostEstimator $costs,
    ) {}

    /**
     * @return array{
     *     store_id: int,
     *     business_date: string,
     *     timezone: string,
     *     scheduled: array<int, array<string, mixed>>,
     *     present: array<int, array<string, mixed>>,
     *     scheduled_absent: array<int, array<string, mixed>>,
     *     present_unscheduled: array<int, array<string, mixed>>,
     *     day_close: array<string, mixed>,
     *     cost: array<string, mixed>
     * }
     */
    public function forDate(int $storeId, string $businessDate): array
    {
        $shifts = Shift::query()
            ->with('employee')
            ->forBoard($storeId, $businessDate)
            ->get();

        $segments = WorkSegment::query()
            ->with('employee')
            ->forBoard($storeId, $businessDate)
            ->get();

        return [
            'store_id' => $storeId,
            'business_date' => $businessDate,
            'timezone' => $this->businessDay->timezoneFor($storeId),
            'scheduled' => $this->scheduled($storeId, $shifts),
            'present' => $this->present($storeId, $segments),
            'scheduled_absent' => $this->scheduledAbsent($shifts, $segments),
            'present_unscheduled' => $this->presentUnscheduled($shifts, $segments),
            'day_close' => $this->dayClose->check($storeId, $businessDate),
            'cost' => $this->costs->estimateFor($shifts, $storeId, $businessDate),
        ];
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array<int, array<string, mixed>>
     */
    private function scheduled(int $storeId, Collection $shifts): array
    {
        return $shifts->map(fn (Shift $shift): array => [
            'shift_id' => $shift->id,
            'employee_id' => $shift->employee_id,
            'employee_name' => $shift->employee?->fullName(),
            'position_id' => $shift->position_id,
            'is_open' => $shift->isOpen(),
            'start_at' => $shift->start_at?->toIso8601String(),
            'end_at' => $shift->end_at?->toIso8601String(),
            'local_start' => $this->localClock($storeId, $shift->start_at),
            'local_end' => $this->localClock($storeId, $shift->end_at),
            'paid_hours' => $shift->paidHours(),
            'publish_state' => $shift->publish_state?->value,
            'availability_check' => $shift->availability_check?->value,
            // The board draws every part of a split as one assignment.
            'split_group_id' => $shift->split_group_id,
            'split_part' => $shift->split_part,
        ])->all();
    }

    /**
     * @param  Collection<int, WorkSegment>  $segments
     * @return array<int, array<string, mixed>>
     */
    private function present(int $storeId, Collection $segments): array
    {
        return $segments->map(fn (WorkSegment $segment): array => [
            'work_segment_id' => $segment->id,
            'employee_id' => $segment->employee_id,
            'employee_name' => $segment->employee?->fullName(),
            'position_id' => $segment->position_id,
            'shift_id' => $segment->shift_id,
            'match_source' => $segment->match_source?->value,
            'time_in' => $segment->time_in?->toIso8601String(),
            'time_out' => $segment->time_out?->toIso8601String(),
            'local_time_in' => $this->localClock($storeId, $segment->time_in),
            'local_time_out' => $this->localClock($storeId, $segment->time_out),
            'is_open_punch' => $segment->isOpenPunch(),
            'break_minutes' => (int) $segment->break_minutes,
            'hours' => $segment->hours === null ? null : (float) $segment->hours,
            'manager_approval' => (bool) $segment->manager_approval,
        ])->all();
    }

    /**
     * Planned and nobody turned up. Open shifts are excluded: an unassigned
     * shift has nobody to be absent.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, WorkSegment>  $segments
     * @return array<int, array<string, mixed>>
     */
    private function scheduledAbsent(Collection $shifts, Collection $segments): array
    {
        $present = $this->employeeIds($segments);

        return $shifts
            ->filter(fn (Shift $shift): bool => $shift->employee_id !== null)
            ->reject(fn (Shift $shift): bool => in_array((int) $shift->employee_id, $present, true))
            ->groupBy('employee_id')
            ->map(fn (Collection $group, $employeeId): array => [
                'employee_id' => (int) $employeeId,
                'employee_name' => $group->first()->employee?->fullName(),
                'shift_ids' => $group->pluck('id')->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Turned up with nothing planned. Read from the punches rather than from
     * shift_id being null, because a segment can be matched to a shift on a
     * neighbouring day and still be unscheduled for this one.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, WorkSegment>  $segments
     * @return array<int, array<string, mixed>>
     */
    private function presentUnscheduled(Collection $shifts, Collection $segments): array
    {
        $scheduled = $this->employeeIds($shifts);

        return $segments
            ->reject(fn (WorkSegment $segment): bool => in_array((int) $segment->employee_id, $scheduled, true))
            ->groupBy('employee_id')
            ->map(fn (Collection $group, $employeeId): array => [
                'employee_id' => (int) $employeeId,
                'employee_name' => $group->first()->employee?->fullName(),
                'work_segment_ids' => $group->pluck('id')->all(),
                'unmatched' => $group->every(fn (WorkSegment $segment): bool => $segment->shift_id === null),
            ])
            ->values()
            ->all();
    }

    /** Store-local HH:MM, the only form the board ever shows. */
    private function localClock(int $storeId, CarbonInterface|string|null $instantUtc): ?string
    {
        return $instantUtc === null
            ? null
            : $this->businessDay->toLocal($storeId, $instantUtc)->format('H:i');
    }

    /**
     * @param  Collection<int, Shift|WorkSegment>  $rows
     * @return array<int, int>
     */
    private function employeeIds(Collection $rows): array
    {
        return $rows->pluck('employee_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
