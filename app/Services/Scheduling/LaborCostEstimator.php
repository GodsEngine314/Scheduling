<?php

namespace App\Services\Scheduling;

use App\Models\EmployeePayHistory;
use App\Models\Shift;
use Carbon\CarbonInterface;

/**
 * What a planned schedule is going to cost.
 *
 * Two rules hold this together:
 *
 *   1. The rate is the one in effect on the shift's OWN business_date. Costing
 *      last month's schedule at today's rate rewrites the past.
 *   2. Nothing is ever written back to shifts. A cached cost column goes stale
 *      the moment a rate changes or a shift moves, and nothing would notice.
 *
 * The output carries derived pay data. Gate it in the caller: a shift manager
 * needs the store total, not a colleague's hourly rate.
 */
class LaborCostEstimator
{
    /**
     * Request-scoped memo of "employee X's rate on date Y", so a day of shifts
     * for the same handful of people is a handful of queries.
     *
     * @var array<string, EmployeePayHistory|null>
     */
    private array $rates = [];

    /** The latest pay history with effective_date <= the date. */
    public function rateOn(int $employeeId, string $businessDate): ?EmployeePayHistory
    {
        $key = $employeeId.'@'.$businessDate;

        if (array_key_exists($key, $this->rates)) {
            return $this->rates[$key];
        }

        return $this->rates[$key] = EmployeePayHistory::query()
            ->rateOn($employeeId, $businessDate)
            ->first();
    }

    /** Null when the shift is open, or when no rate was in effect that day. */
    public function estimateShift(Shift $shift): ?float
    {
        if ($shift->employee_id === null) {
            return null;
        }

        $rate = $this->rateOn((int) $shift->employee_id, $this->dateString($shift->business_date));

        if ($rate === null) {
            return null;
        }

        return round($shift->paidHours() * $rate->hourlyRate(), 2);
    }

    /**
     * @return array{
     *     store_id: int,
     *     business_date: string,
     *     planned_hours: float,
     *     planned_cost: float,
     *     unpriced_hours: float,
     *     per_employee: array<int, array<string, mixed>>
     * }
     */
    public function estimateDay(int $storeId, string $businessDate): array
    {
        $shifts = Shift::query()
            ->with('employee')
            ->forBoard($storeId, $businessDate)
            ->get();

        return $this->estimateFor($shifts, $storeId, $businessDate);
    }

    /**
     * The summing half, split out so a caller that already holds the day's
     * shifts (the board) does not fetch them a second time.
     *
     * unpriced_hours is reported rather than swallowed: open shifts and
     * employees with no pay history contribute hours to the plan but no money,
     * and a total that hides them reads as cheaper than the day will be.
     *
     * @param  iterable<int, Shift>  $shifts
     */
    public function estimateFor(iterable $shifts, ?int $storeId = null, ?string $businessDate = null): array
    {
        $plannedHours = 0.0;
        $plannedCost = 0.0;
        $unpricedHours = 0.0;
        $perEmployee = [];

        foreach ($shifts as $shift) {
            $hours = $shift->paidHours();
            $plannedHours += $hours;

            $cost = $this->estimateShift($shift);

            if ($cost === null) {
                $unpricedHours += $hours;
            } else {
                $plannedCost += $cost;
            }

            if ($shift->employee_id === null) {
                continue;
            }

            $employeeId = (int) $shift->employee_id;

            $perEmployee[$employeeId] ??= [
                'employee_id' => $employeeId,
                'name' => $shift->relationLoaded('employee') ? $shift->employee?->fullName() : null,
                'hours' => 0.0,
                'cost' => 0.0,
                'rate_known' => $cost !== null,
            ];

            $perEmployee[$employeeId]['hours'] = round($perEmployee[$employeeId]['hours'] + $hours, 2);
            $perEmployee[$employeeId]['cost'] = round($perEmployee[$employeeId]['cost'] + (float) $cost, 2);
            $perEmployee[$employeeId]['rate_known'] = $perEmployee[$employeeId]['rate_known'] && $cost !== null;
        }

        return [
            'store_id' => $storeId,
            'business_date' => $businessDate,
            'planned_hours' => round($plannedHours, 2),
            'planned_cost' => round($plannedCost, 2),
            'unpriced_hours' => round($unpricedHours, 2),
            'per_employee' => array_values($perEmployee),
        ];
    }

    private function dateString(CarbonInterface|string|null $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
    }
}
