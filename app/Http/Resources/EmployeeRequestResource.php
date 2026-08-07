<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\EmployeeRequestDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One thing an employee asked the schedule to do.
 *
 * status is a cache of the latest decision, so latest_decision is exposed
 * alongside it: when the two ever disagree the decision row is the truth, and a
 * client that can see both can say so instead of quietly believing the cache.
 *
 * employee_id is the SUBJECT of the request; requested_by_user_id is whoever
 * typed it. Employees have no logins here, so a manager filing on someone's
 * behalf is the normal case and the two must not be conflated.
 *
 * @mixin EmployeeRequest
 */
class EmployeeRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'employee_id' => (int) $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn (Employee $employee): array => [
                'id' => (int) $employee->id,
                'name' => $employee->fullName(),
            ]),

            'request_type' => $this->request_type?->value,
            'description' => $this->description,

            // Null for the types that are not date-ranged. A time_off request
            // cannot get here without them — the service refuses one.
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),

            'shift_id' => $this->shift_id === null ? null : (int) $this->shift_id,
            'store_id' => $this->store_id === null ? null : (int) $this->store_id,

            'status' => $this->status?->value,
            'is_pending' => $this->status?->value === 'pending',

            'requested_by_user_id' => $this->requested_by_user_id === null
                ? null
                : (int) $this->requested_by_user_id,

            'latest_decision' => $this->whenLoaded(
                'latestDecision',
                fn (EmployeeRequestDecision $decision): array => $this->decision($decision),
            ),
            'decisions' => $this->whenLoaded(
                'decisions',
                fn ($decisions) => $decisions
                    ->map(fn (EmployeeRequestDecision $decision): array => $this->decision($decision))
                    ->values(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function decision(EmployeeRequestDecision $decision): array
    {
        return [
            'id' => (int) $decision->id,
            'decision' => $decision->decision?->value,
            'user_id' => $decision->user_id === null ? null : (int) $decision->user_id,
            'notes' => $decision->notes,
            'completed_at' => $decision->completed_at?->toIso8601String(),
            'created_at' => $decision->created_at?->toIso8601String(),
        ];
    }
}
