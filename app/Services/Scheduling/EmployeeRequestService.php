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
     * What a correction may touch.
     *
     * Deliberately shorter than CREATABLE. employee_id is the SUBJECT — change
     * it and this is a different request, not a corrected one. status is a
     * cache of the decision trail. requested_by_user_id records who typed the
     * original and is not rewritten by whoever fixes a typo in it.
     */
    private const EDITABLE = [
        'request_type',
        'description',
        'start_date',
        'end_date',
        'shift_id',
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

        $this->assertDates(
            $type,
            $this->dateString($payload['start_date'] ?? null),
            $this->dateString($payload['end_date'] ?? null),
        );

        return DB::transaction(function () use ($payload, $type): EmployeeRequest {
            return EmployeeRequest::query()->create(array_merge($payload, [
                'request_type' => $type,
                'store_id' => $payload['store_id'] ?? $this->inferStoreId($payload),
                'status' => RequestStatus::Pending,
            ]));
        });
    }

    /**
     * Correct a request that has not been ruled on yet.
     *
     * PENDING ONLY. A decided request is a record of what somebody agreed to;
     * editing its dates afterwards would leave the decision row pointing at
     * something that was never decided. Wrong dates on an approved request are
     * fixed by cancelling it and raising the right one, which is the version
     * that leaves a trail.
     *
     * NEVER TOUCHES status. That column is a cache of the latest decision row
     * and moves only through decide() — see the class docblock. Nor
     * employee_id: the subject is what the request IS, and changing it makes it
     * a different request rather than a corrected one.
     */
    public function update(EmployeeRequest $request, array $attributes): EmployeeRequest
    {
        if ($request->status !== RequestStatus::Pending) {
            throw new SchedulingException(
                'Only a pending request can be edited. This one is '.$request->status->value
                .' — cancel it and raise a new one.',
                ['employee_request_id' => (int) $request->id, 'status' => $request->status->value],
            );
        }

        $payload = array_intersect_key($attributes, array_flip(self::EDITABLE));

        // The type decides which date rules apply, so it has to be resolved
        // before them — and a request that arrives without one keeps its own.
        $type = array_key_exists('request_type', $payload)
            ? $this->requestType($payload['request_type'])
            : $request->request_type;

        // Merged against what is already stored: an edit that sends only an
        // end_date must still be checked against the start_date it will sit
        // next to, not against nothing.
        $start = $this->dateString($payload['start_date'] ?? $request->start_date);
        $end = $this->dateString($payload['end_date'] ?? $request->end_date);

        $this->assertDates($type, $start, $end);

        return DB::transaction(function () use ($request, $payload, $type): EmployeeRequest {
            $request->forceFill(array_merge($payload, ['request_type' => $type]))->save();

            return $request;
        });
    }

    /**
     * Withdraw a request.
     *
     * A DECISION, NOT A DELETE. employee_request_decisions is the audit trail,
     * and a deleted row answers no question anybody later asks — who asked for
     * the day off, and what happened. Cancelling writes the same kind of row an
     * approval does, so the story stays readable in one place.
     *
     * Allowed from approved as well as pending: somebody cancelling leave they
     * no longer need is the ordinary case, and the approval it reverses stays
     * in the trail above it.
     */
    public function withdraw(EmployeeRequest $request, ?int $userId = null, ?string $notes = null): EmployeeRequestDecision
    {
        if ($request->status === RequestStatus::Cancelled) {
            throw new SchedulingException('That request is already withdrawn.', [
                'employee_request_id' => (int) $request->id,
            ]);
        }

        return $this->decide($request, RequestDecision::Cancelled, $userId, $notes);
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
     * The date rules, in one place so create() and update() cannot drift.
     *
     * "A time-off request without dates is a note, not a request." Without them
     * it is invisible to the conflict check, which is the only reason the row is
     * worth storing.
     */
    private function assertDates(RequestType $type, ?string $start, ?string $end): void
    {
        if ($type === RequestType::TimeOff && ($start === null || $end === null)) {
            throw new SchedulingException('A time_off request needs a start_date and an end_date.');
        }

        if ($start !== null && $end !== null && $end < $start) {
            throw new SchedulingException('A request cannot end before it starts.', [
                'start_date' => $start,
                'end_date' => $end,
            ]);
        }
    }

    /**
     * Compare dates as Y-m-d strings, never as mixed.
     *
     * start_date and end_date are cast to DateOnly, so a value read back off the
     * model is a Carbon while one straight off the request is a string. A `<`
     * between the two compares a formatted object against a string and is not
     * the comparison anybody intended.
     */
    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : (string) $value;
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
