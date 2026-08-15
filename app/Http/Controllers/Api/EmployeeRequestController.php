<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Requests\Api\EmployeeRequestDecideRequest;
use App\Http\Requests\Api\EmployeeRequestIndexRequest;
use App\Http\Requests\Api\EmployeeRequestStoreRequest;
use App\Http\Requests\Api\EmployeeRequestUpdateRequest;
use App\Http\Requests\Api\EmployeeRequestWithdrawRequest;
use App\Http\Resources\EmployeeRequestResource;
use App\Models\EmployeeRequest;
use App\Services\Scheduling\EmployeeRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * What employees ask the schedule to do, and what a manager decides about it.
 *
 * The status column is a cache of the latest decision row, written in the same
 * transaction by EmployeeRequestService. That is why the PUT here cannot touch
 * it: the only way status ever moves is through decide() or withdraw(), each of
 * which leaves behind the audit row the cache is derived from.
 *
 * So the writes divide cleanly, and the status codes say which is which:
 *
 *   store     201   a new request, always pending
 *   update    200   a correction, and the only true edit — pending only
 *   decide    201   appends a decision, re-caches status
 *   withdraw  201   appends a CANCELLED decision. Never a DELETE: a deleted row
 *                   answers nothing anybody later asks about who requested what.
 *
 * There is no destroy(), and that is the design rather than a gap.
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
     * Correct a request nobody has ruled on yet.
     *
     * 200, not 201: unlike decide(), this leaves no new row behind — it is the
     * one write on this resource that really is an edit. The service refuses it
     * once a decision exists, because editing then would leave that decision
     * pointing at something that was never decided.
     */
    public function update(EmployeeRequestUpdateRequest $request, EmployeeRequest $employeeRequest): JsonResponse
    {
        return $this->attempt(function () use ($request, $employeeRequest): JsonResponse {
            $this->requests->update($employeeRequest, $request->validated());

            return EmployeeRequestResource::make($employeeRequest->load(['employee', 'latestDecision']))
                ->response();
        });
    }

    /**
     * Withdraw a request.
     *
     * 201 like decide(), and for the same reason: this IS a decision. It writes
     * a cancelled row rather than deleting anything, so the trail still answers
     * who asked for the day off and what became of it.
     */
    public function withdraw(EmployeeRequestWithdrawRequest $request, EmployeeRequest $employeeRequest): JsonResponse
    {
        return $this->attempt(function () use ($request, $employeeRequest): JsonResponse {
            $decision = $this->requests->withdraw(
                $employeeRequest,
                $this->actingUserId($request),
                $request->validated('notes'),
            );

            return EmployeeRequestResource::make($employeeRequest->load(['employee', 'latestDecision']))
                ->additional(['meta' => ['decision_id' => (int) $decision->id]])
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
