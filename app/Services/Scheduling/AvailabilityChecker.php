<?php

namespace App\Services\Scheduling;

use App\Enums\AvailabilityCheck;
use App\Enums\DayOfWeek;
use App\Models\Employee;
use App\Models\EmployeeAvailabilityWindow;
use App\Models\Shift;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Is this block of work inside a window the employee gave us?
 *
 * Warns, never blocks — the answer is stored on shifts.availability_check and
 * the scheduler decides what to do about it.
 *
 * The windows are wall-clock times in the store's zone with no date attached,
 * so the comparison only means anything in store-local time; converting the
 * shift is the whole job. Each part of a split shift is checked on its own:
 * the gap between parts is not work, so nothing has to cover it.
 */
class AvailabilityChecker
{
    public function __construct(private readonly BusinessDay $businessDay) {}

    /**
     * $storeId decides the timezone. It defaults to the employee's primary
     * store, but a caller holding a shift should always pass the SHIFT's store:
     * people cover stores that are not their own, sometimes in another zone.
     */
    public function check(
        ?Employee $employee,
        CarbonInterface $startUtc,
        CarbonInterface $endUtc,
        ?int $storeId = null,
    ): AvailabilityCheck {
        // An open shift has nobody to be available. Not a warning — a fact.
        if ($employee === null) {
            return AvailabilityCheck::Unknown;
        }

        $windows = $this->windowsFor($employee);

        // No projected availability at all is unknown, not "outside". Flagging
        // every shift for an employee whose availability never arrived would
        // train schedulers to ignore the flag.
        if ($windows->isEmpty()) {
            return AvailabilityCheck::Unknown;
        }

        $storeId ??= $employee->primary_store_id !== null ? (int) $employee->primary_store_id : null;

        $localStart = $this->businessDay->toLocal($storeId, $startUtc);
        $localEnd = $this->businessDay->toLocal($storeId, $endUtc);

        foreach ($this->candidateWindows($windows, $localStart) as $window) {
            if ($window->covers($localStart, $localEnd)) {
                return AvailabilityCheck::Ok;
            }
        }

        return AvailabilityCheck::OutsideAvailability;
    }

    /** The same question asked of a saved row. */
    public function forShift(Shift $shift): AvailabilityCheck
    {
        if ($shift->start_at === null || $shift->end_at === null) {
            return AvailabilityCheck::Unknown;
        }

        return $this->check(
            $shift->employee_id === null ? null : ($shift->employee ?? Employee::query()->find($shift->employee_id)),
            $shift->start_at,
            $shift->end_at,
            (int) $shift->store_id,
        );
    }

    /**
     * The local START day decides which windows apply — plus the day before it,
     * because a window whose available_to is earlier than its available_from
     * wraps past midnight and is anchored on the evening it began. A shift
     * starting Tuesday 00:30 is covered by Monday 20:00->02:00, not by anything
     * filed under Tuesday. The window itself rejects the wrong anchor.
     *
     * @param  Collection<int, EmployeeAvailabilityWindow>  $windows
     * @return Collection<int, EmployeeAvailabilityWindow>
     */
    private function candidateWindows(Collection $windows, CarbonInterface $localStart): Collection
    {
        $startDay = DayOfWeek::fromDate($localStart);
        $previousDay = DayOfWeek::fromCarbonDayOfWeek(($startDay->carbonDayOfWeek() + 6) % 7);

        $days = [$startDay->value, $previousDay->value];

        return $windows->filter(
            fn (EmployeeAvailabilityWindow $window): bool => in_array($window->day_of_week?->value, $days, true)
        );
    }

    /**
     * All of them in one go. An employee holds a handful of rows, and reading
     * the whole set is what lets "has no availability" be told apart from "is
     * not available then".
     *
     * @return Collection<int, EmployeeAvailabilityWindow>
     */
    private function windowsFor(Employee $employee): Collection
    {
        return $employee->relationLoaded('availabilityWindows')
            ? $employee->availabilityWindows
            : $employee->availabilityWindows()->get();
    }
}
