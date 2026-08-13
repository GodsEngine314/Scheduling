<?php

namespace App\Services\Scheduling;

use App\Enums\RequestDecision;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Exceptions\SchedulingException;
use App\Models\EmployeeRequest;
use App\Models\EmployeeRequestDecision;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * What employees ask the schedule to do, and what a manager decides about it.
 *
 * employee_requests.status is a CACHE of the latest row in
 * employee_request_decisions. The decision rows are the truth; the column
 * exists so "what is outstanding for this store this week" is one indexed query
 * instead of a correlated subquery. Which means the two are written together,
 * in one transaction, always — a drifted cache makes the board lie about
 * approved time off, and approved time off is what the shift conflict check
 * reads.
 */
class EmployeeRequestService
{
    private const CREATABLE = [
        'employee_id',
        'request_type',
        'description',
        'start_date',
        'end_date',
        'shift_id',
        'store_id',
        'requested_by_user_id',
    ];

    /**
     * Always created pending. A request cannot be born approved — approval is a
     * decision, and a decision leaves a row behind.
     */
    public function create(array $attributes): EmployeeRequest
    {
        $payload = array_intersect_key($attributes, array_flip(self::CREATABLE));

        if (($payload['employee_id'] ?? null) === null) {
            throw new SchedulingException('An employee request needs an employee_id.');
        }

        $type = $this->requestType($payload['request_type'] ?? null);

        // "A time-off request without dates is a note, not a request." Without
        // them it is invisible to the conflict check, which is the only reason
        // the row is worth storing.
        if ($type === RequestType::TimeOff && (($payload['start_date'] ?? null) === null || ($payload['end_date'] ?? null) === null)) {
            throw new SchedulingException('A time_off request needs a start_date and an end_date.');
        }

        if (($payload['start_date'] ?? null) !== null
            && ($payload['end_date'] ?? null) !== null
            && $payload['end_date'] < $payload['start_date']) {
            throw new SchedulingException('A request cannot end before it starts.', [
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'],
            ]);
        }

        return DB::transaction(function () use ($payload, $type): EmployeeRequest {
            return EmployeeRequest::query()->create(array_merge($payload, [
                'request_type' => $type,
                'store_id' => $payload['store_id'] ?? $this->inferStoreId($payload),
                'status' => RequestStatus::Pending,
            ]));
        });
    }

    /**
     * Record a decision and re-cache the status it produces.
     *
     * Both writes or neither. Returns the decision row; the passed request is
     * updated in place, so a caller holding it sees the new status without a
     * refresh.
     */
    public function decide(
        EmployeeRequest $request,
        RequestDecision $decision,
        ?int $userId = null,
        ?string $notes = null,
    ): EmployeeRequestDecision {
        return DB::transaction(function () use ($request, $decision, $userId, $notes): EmployeeRequestDecision {
            $row = EmployeeRequestDecision::query()->create([
                'employee_request_id' => $request->id,
                'user_id' => $userId,
                'decision' => $decision,
                'notes' => $notes,
                // Every decision here settles the request — there is no
                // multi-step approval in this table.
                'completed_at' => now(),
            ]);

            $request->forceFill(['status' => $decision->toRequestStatus()])->save();

            return $row;
        });
    }

    /**
     * A request about a specific shift belongs to that shift's store; the board
     * filters on store_id and a null there hides the request from everyone.
     */
    private function inferStoreId(array $payload): ?int
    {
        if (($payload['shift_id'] ?? null) === null) {
            return null;
        }

        $storeId = Shift::query()->whereKey($payload['shift_id'])->value('store_id');

        return $storeId === null ? null : (int) $storeId;
    }

    private function requestType(RequestType|string|null $type): RequestType
    {
        if ($type instanceof RequestType) {
            return $type;
        }

        $resolved = $type === null ? null : RequestType::tryFrom($type);

        if ($resolved === null) {
            throw new SchedulingException('An employee request needs a valid request_type.', [
                'request_type' => $type,
            ]);
        }

        return $resolved;
    }
}
