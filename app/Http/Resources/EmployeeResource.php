<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmployeeAvailabilityWindow;
use App\Models\EmployeePayHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One person on the roster, with the availability the schedule is built inside.
 *
 * current_rate is the only field here that can be absent, and it is the most
 * sensitive thing in the schema. It appears ONLY when the controller has passed
 * the ?include=cost gate — see ApiController::wantsCost(), which is also where
 * a real authorisation check belongs.
 *
 * birth_date is not exposed. What a scheduler actually needs from it is whether
 * minor labour rules apply, so that one bit is published and the date is not.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    private ?EmployeePayHistory $rate = null;

    private bool $rateVisible = false;

    /** Called by the controller, and only after the cost gate has been passed. */
    public function withCurrentRate(?EmployeePayHistory $rate): static
    {
        $this->rate = $rate;
        $this->rateVisible = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),

            'employment_type' => $this->employment_type?->value,
            'current_status' => $this->current_status?->value,
            'current_status_effective_date' => $this->current_status_effective_date?->toDateString(),
            'is_schedulable' => $this->current_status?->isSchedulable() ?? false,

            'primary_store_id' => $this->primary_store_id === null ? null : (int) $this->primary_store_id,
            'primary_position_id' => $this->primary_position_id === null ? null : (int) $this->primary_position_id,

            'primary_phone' => $this->primary_phone,
            'primary_email' => $this->primary_email,

            'is_minor' => $this->isMinor(),

            'availability_windows' => $this->whenLoaded(
                'availabilityWindows',
                fn ($windows) => $windows
                    ->map(fn (EmployeeAvailabilityWindow $window): array => $this->window($window))
                    ->values(),
            ),

            'current_rate' => $this->when($this->rateVisible, fn (): ?array => $this->currentRate()),
        ];
    }

    /**
     * A window carries no date, so wraps_midnight is published rather than left
     * for the client to rediscover from the ordering of the two times. The rule
     * is available_to < available_from, and getting it wrong moves a closing
     * shift onto the wrong day.
     *
     * @return array<string, mixed>
     */
    private function window(EmployeeAvailabilityWindow $window): array
    {
        return [
            'id' => (int) $window->id,
            'day_of_week' => $window->day_of_week?->value,
            'available_from' => $window->available_from,
            'available_to' => $window->available_to,
            'shift_type' => $window->shift_type?->value,
            'wraps_midnight' => $window->wrapsMidnight(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function currentRate(): ?array
    {
        if ($this->rate === null) {
            return null;
        }

        return [
            'base_pay' => (float) $this->rate->base_pay,
            'performance_pay' => (float) $this->rate->performance_pay,
            'hourly_rate' => $this->rate->hourlyRate(),
            'effective_date' => $this->rate->effective_date?->toDateString(),
        ];
    }

    /**
     * As of today, not as of any particular shift. A shift-date answer is what
     * ShiftService::conflicts() returns; this is the roster telling a scheduler
     * who to look twice at before placing a late one.
     */
    private function isMinor(): ?bool
    {
        if ($this->birth_date === null) {
            return null;
        }

        return CarbonImmutable::instance($this->birth_date)
            ->addYears(18)
            ->greaterThan(CarbonImmutable::now());
    }
}
