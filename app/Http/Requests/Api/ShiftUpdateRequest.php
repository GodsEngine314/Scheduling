<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ResolvesLocalWindow;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a planned shift. Every field is optional; ShiftService falls back to
 * what the row already holds.
 *
 * The same two time shapes as ShiftStoreRequest, with one difference: a local
 * pair sent without a business_date is read against the shift's OWN date, so
 * "move it to 17:00-21:00" does not require restating the day.
 *
 * employee_id => null is meaningful and is passed through: it UNASSIGNS the
 * shift, turning it back into an open one. That is why the key is only included
 * when the caller actually sent it — an absent employee_id must not blank the
 * person already on the shift.
 */
class ShiftUpdateRequest extends FormRequest
{
    use ResolvesLocalWindow;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],

            'business_date' => ['sometimes', 'date_format:Y-m-d'],

            // end before start is a midnight crossing and is accepted; end
            // equal to start is a typo that would become a 24-hour shift.
            'start_time' => ['sometimes', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required_with:start_time', 'date_format:H:i', 'different:start_time'],
            'start_at' => ['sometimes', 'required_with:end_at', 'date'],
            'end_at' => ['sometimes', 'required_with:start_at', 'date', 'after:start_at'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'repeat_rule' => ['sometimes', 'string', 'max:32'],
            'repeat_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The validated input as ShiftService::update() attributes.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $validated = $this->validated();

        $attributes = array_intersect_key($validated, array_flip([
            'store_id',
            'employee_id',
            'position_id',
            'business_date',
            'notes',
            'repeat_rule',
            'repeat_until',
        ]));

        if (($validated['start_time'] ?? null) !== null) {
            /** @var Shift $shift */
            $shift = $this->route('shift');

            $localDate = $validated['business_date'] ?? $shift->business_date->toDateString();

            [$startLocal, $endLocal] = $this->localWindow(
                $localDate,
                $validated['start_time'],
                $validated['end_time'],
            );

            $attributes['business_date'] = $localDate;
            $attributes['start_at_local'] = $startLocal;
            $attributes['end_at_local'] = $endLocal;

            return $attributes;
        }

        if (($validated['start_at'] ?? null) !== null) {
            $attributes['start_at'] = $validated['start_at'];
            $attributes['end_at'] = $validated['end_at'];
        }

        return $attributes;
    }
}
