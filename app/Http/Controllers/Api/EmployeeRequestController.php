<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Requests\Api\EmployeeRequestDecideRequest;
use App\Http\Requests\Api\EmployeeRequestIndexRequest;
use App\Http\Requests\Api\EmployeeRequestStoreRequest;
use App\Http\Resources\EmployeeRequestResource;
use App\Models\EmployeeRequest;
use App\Services\Scheduling\EmployeeRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * What employees ask the schedule to do, and what a manager decides about it.
 *
 * The status column is a cache of the latest decision row, written in the same
 * transaction by EmployeeRequestService. That is why there is no PUT here: the
 * only way status ever moves is through decide(), which leaves the audit row
 * behind that the cache is derived from.
 */
class EmployeeRequestController extends ApiController
{
    public function __construct(private readonly EmployeeRequestService $requests) {}

    public function index(EmployeeRequestIndexRequest $request): JsonResponse
    {
        $storeId = (int) $request->validated('store');
        $from = $request->validated('from');
        $to = $request->validated('to');

        $query = EmployeeRequest::query()->with(['employee', 'latestDecision']);

        if ($from !== null && $to !== null) {
            // This scope compares against start_date, so it drops the types
            // that carry no dates (availability_change, other). That is the
            // right answer to a range question and the wrong one to any other,
            // which is why it only applies when a range was actually asked for.
            $query->forStoreBetween($storeId, (string) $from, (string) $to);
        } else {
            $query->where('store_id', $storeId);
        }

        $requests = $query
            ->when($request->validated('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->validated('request_type'), fn (Builder $q, $type) => $q->where('request_type', $type))
            ->when($request->validated('employee_id'), fn (Builder $q, $id) => $q->where('employee_id', $id))
            ->orderByDesc('id')
            ->get();

        return EmployeeRequestResource::collection($requests)
            ->additional(['meta' => [
                'store_id' => $storeId,
                'count' => $requests->count(),
                'pending' => $requests
                    ->filter(fn (EmployeeRequest $row): bool => $row->status === RequestStatus::Pending)
                    ->count(),
            ]])
            ->response();
    }

    public function store(EmployeeRequestStoreRequest $request): JsonResponse
    {
        return $this->attempt(function () use ($request): JsonResponse {
            $employeeRequest = $this->requests->create(array_merge($request->validated(), [
                // Who TYPED it, from the resolved user. employee_id in the body
                // is who it is ABOUT, and the two are routinely different —
                // employees have no logins here.
                'requested_by_user_id' => $this->actingUserId($request),
            ]));

            return EmployeeRequestResource::make($employeeRequest->load(['employee', 'latestDecision']))
                ->response()
                ->setStatusCode(201);
        });
    }

    /**
     * 201: a decision is a row in employee_request_decisions, not an edit to
     * the request. The request comes back with its re-cached status, and the
     * new decision's id is in meta.
     */
    public function decide(EmployeeRequestDecideRequest $request, EmployeeRequest $employeeRequest): JsonResponse
    {
        return $this->attempt(function () use ($request, $employeeRequest): JsonResponse {
            $decision = $this->requests->decide(
                $employeeRequest,
                $request->decision(),
                $this->actingUserId($request),
                $request->validated('notes'),
            );

            return EmployeeRequestResource::make($employeeRequest->load(['employee', 'latestDecision']))
                ->additional(['meta' => ['decision_id' => (int) $decision->id]])
                ->response()
                ->setStatusCode(201);
        });
    }
}
