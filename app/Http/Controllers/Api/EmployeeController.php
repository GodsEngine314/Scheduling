<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\EmployeeIndexRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeePayHistory;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * The roster: who can be put on this store's board, and when they said they
 * are free.
 *
 * THE RATE IS THE DANGEROUS PART. employee_pay_histories is the most sensitive
 * table in the schema and this is a list endpoint, so current_rate appears only
 * behind ?include=cost — see ApiController::wantsCost(), which is also the one
 * place a real authorisation check has to land.
 */
class EmployeeController extends ApiController
{
    public function __construct(private readonly BusinessDay $businessDay) {}

    public function index(EmployeeIndexRequest $request): JsonResponse
    {
        $storeId = (int) $request->validated('store');

        $employees = Employee::query()
            ->with('availabilityWindows')
            // Terminated staff stay in the projection — hiring publishes no
            // employee.deleted event — so "off the roster" is a status filter.
            ->when(
                ! $request->boolean('include_inactive'),
                fn (Builder $query) => $query->schedulable(),
            )
            // Primary store OR a store assignment: people cover stores that are
            // not their own, and a roster that hides them cannot staff a
            // Saturday. The nested closure keeps the OR from leaking out and
            // swallowing the status filter.
            ->where(fn (Builder $query) => $query
                ->where('primary_store_id', $storeId)
                ->orWhereHas('storeAssignments', fn (Builder $assignment) => $assignment->where('store_id', $storeId)))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $rates = $this->wantsCost($request)
            ? $this->currentRates($employees, $this->today($storeId))
            : null;

        $resources = $employees->map(function (Employee $employee) use ($rates): EmployeeResource {
            $resource = EmployeeResource::make($employee);

            return $rates === null
                ? $resource
                : $resource->withCurrentRate($rates->get((int) $employee->id));
        });

        return EmployeeResource::collection($resources)
            ->additional(['meta' => [
                'store_id' => $storeId,
                'timezone' => $this->businessDay->timezoneFor($storeId),
                'count' => $employees->count(),
            ]])
            ->response();
    }

    /**
     * The rate in effect today for the WHOLE roster, in one query.
     *
     * Same rule as EmployeePayHistory::scopeRateOn — the latest effective_date
     * on or before the day, ties broken by id — asked of every employee at
     * once. Ascending order plus keyBy means the last row for an employee wins,
     * which is that same "latest" read from the other end. Doing it per
     * employee would be one query per person on a list endpoint.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, EmployeePayHistory>
     */
    private function currentRates(Collection $employees, string $onDate): Collection
    {
        return EmployeePayHistory::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->where('effective_date', '<=', $onDate)
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (EmployeePayHistory $rate): int => (int) $rate->employee_id);
    }

    /**
     * "Today" at the store, not on the server. A roster loaded at 00:30 UTC is
     * still yesterday in New York, and a rate that changes at midnight local
     * has to change at midnight local.
     */
    private function today(int $storeId): string
    {
        return $this->businessDay->businessDate($storeId, now());
    }
}
