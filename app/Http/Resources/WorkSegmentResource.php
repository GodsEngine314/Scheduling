<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Position;
use App\Models\WorkSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One actual worked block — a punch, or hours a manager entered by hand.
 *
 * AN OPEN PUNCH IS NOT A BLANK ROW. time_out null means somebody is still on
 * the clock, which is a fact the day close reports separately and a manager has
 * to act on. Left as three nulls (time_out, hours, and nothing else) it reads
 * as missing data, so it is stated three ways instead: is_open_punch, a `state`
 * a UI can switch on, and open_since carrying the time they clocked in.
 *
 * tcp_payload is deliberately not here. It is the raw vendor record, it is
 * large, and it holds fields this API has made no promise about.
 *
 * @mixin WorkSegment
 */
class WorkSegmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isOpenPunch = $this->isOpenPunch();

        return [
            'id' => (int) $this->id,
            'employee_id' => (int) $this->employee_id,
            'store_id' => (int) $this->store_id,
            'position_id' => $this->position_id === null ? null : (int) $this->position_id,

            'employee' => $this->whenLoaded('employee', fn (Employee $employee): array => [
                'id' => (int) $employee->id,
                'name' => $employee->fullName(),
            ]),
            'position' => $this->whenLoaded('position', fn (Position $position): array => [
                'id' => (int) $position->id,
                'label' => $position->label,
            ]),

            // How these hours came to be tied to a planned shift — or that they
            // are not tied to one at all, which is what the board's
            // "present, unscheduled" column is built from.
            'shift_id' => $this->shift_id === null ? null : (int) $this->shift_id,
            'match_source' => $this->match_source?->value,
            'is_matched' => $this->shift_id !== null,

            'business_date' => $this->business_date?->toDateString(),
            'time_in' => $this->time_in?->toIso8601String(),
            'time_out' => $this->time_out?->toIso8601String(),

            'is_open_punch' => $isOpenPunch,
            'open_since' => $isOpenPunch ? $this->time_in?->toIso8601String() : null,
            'state' => $this->state(),

            'break_minutes' => (int) $this->break_minutes,
            // TCP's number when TCP gave us one, ours after a correction. Null
            // while the punch is open — there is nothing to total yet.
            'hours' => $this->hours === null ? null : (float) $this->hours,

            'manager_approval' => (bool) $this->manager_approval,
            'approved_by_user_id' => $this->approved_by_user_id === null ? null : (int) $this->approved_by_user_id,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'employee_approval' => (bool) $this->employee_approval,

            'origin' => $this->origin?->value,
            'cost_code_name' => $this->cost_code_name,
            'labor_code' => $this->labor_code,
            'notes' => $this->notes,

            'times_corrected_at' => $this->times_corrected_at?->toIso8601String(),
            'times_corrected_by_user_id' => $this->times_corrected_by_user_id === null
                ? null
                : (int) $this->times_corrected_by_user_id,

            'tcp_segment_id' => $this->tcp_segment_id,
            'tcp_synced_at' => $this->tcp_synced_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The three states the day close cares about, as one field.
     *
     * open_punch is kept apart from awaiting_approval on purpose: an open punch
     * has no hours to approve, so folding the two together would let a manager
     * clear the list without ever seeing that somebody's time is missing.
     */
    private function state(): string
    {
        if ($this->isOpenPunch()) {
            return 'open_punch';
        }

        return $this->manager_approval ? 'approved' : 'awaiting_approval';
    }
}
