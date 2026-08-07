<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Position;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One planned shift, as the schedule builder sees it.
 *
 * estimated_cost is the only field here that can be absent. It is derived from
 * the employee's hourly rate — divide it by paid_hours and you have the rate
 * back — so it is opt-in via ?include=cost and the controller decides. The key
 * is OMITTED rather than nulled when it was not asked for, because null already
 * means something else: no pay history was in effect on that business_date.
 *
 * Times are UTC. The store's timezone travels once in the collection meta
 * instead of being repeated on every row.
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    private ?float $estimatedCost = null;

    private bool $costVisible = false;

    /** Called by the controller, and only after the cost gate has been passed. */
    public function withEstimatedCost(?float $cost): static
    {
        $this->estimatedCost = $cost;
        $this->costVisible = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'store_id' => (int) $this->store_id,
            'employee_id' => $this->employee_id === null ? null : (int) $this->employee_id,
            'position_id' => $this->position_id === null ? null : (int) $this->position_id,

            // A missing employee is a STATE — a slot on the board waiting for
            // somebody — not an omission, so say so rather than leaving the
            // client to infer it from a null.
            'is_open' => $this->isOpen(),

            'employee' => $this->whenLoaded('employee', fn (Employee $employee): array => [
                'id' => (int) $employee->id,
                'name' => $employee->fullName(),
            ]),
            'position' => $this->whenLoaded('position', fn (Position $position): array => [
                'id' => (int) $position->id,
                'label' => $position->label,
            ]),

            'business_date' => $this->business_date?->toDateString(),
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'unpaid_break_minutes' => (int) $this->unpaid_break_minutes,
            'paid_hours' => $this->paidHours(),

            'notes' => $this->notes,

            'repeat_rule' => $this->repeat_rule,
            'repeat_until' => $this->repeat_until?->toDateString(),
            'series_id' => $this->series_id,

            // Both halves, always. A part number without its group cannot be
            // drawn as one assignment.
            'split_group_id' => $this->split_group_id,
            'split_part' => $this->split_part === null ? null : (int) $this->split_part,
            'is_split' => $this->isSplit(),

            'publish_state' => $this->publish_state?->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'humanity_shift_id' => $this->humanity_shift_id,
            'publish_attempts' => (int) $this->publish_attempts,
            'last_publish_error' => $this->last_publish_error,

            // Warns, never blocks. The scheduler is shown it and decides.
            'availability_check' => $this->availability_check?->value,

            'estimated_cost' => $this->when($this->costVisible, fn (): ?float => $this->estimatedCost),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
