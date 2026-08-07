<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ResolvesLocalWindow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a planned shift.
 *
 * TWO WAYS TO SAY WHEN, and they may not be mixed:
 *
 *   business_date + start_time + end_time — store-local wall clock, H:i. What a
 *   scheduling UI actually holds, and the only shape that can state an
 *   overnight shift without lying about the date: end_time <= start_time means
 *   the block crosses midnight and is accepted, not rejected.
 *
 *   start_at + end_at — UTC instants, for a caller that already converted.
 *
 * The local pair wins if both arrive, matching ShiftService, which prefers
 * *_local for the same reason: it is the more specific statement of intent.
 *
 * publish_state, humanity_shift_id, payload_fingerprint and the publish_*
 * columns are absent on purpose. They belong to the publisher. A caller able to
 * set them could make this service believe Humanity holds a shift it has never
 * been told about, and the next publish would then skip it forever.
 */
class ShiftStoreRequest extends FormRequest
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
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            // Null is an OPEN shift: placed on the board before anyone is
            // assigned. A missing employee here is a state, not an omission.
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],

            'business_date' => ['required_with:start_time', 'nullable', 'date_format:Y-m-d'],

            // required_with on each half of a pair: start_time with end_at is a
            // caller mixing the two shapes, and would otherwise reach the
            // service as a half-stated window.
            //
            // end_time BEFORE start_time is accepted — that is a midnight
            // crossing. end_time EQUAL to start_time is not: it states a
            // zero-length block, and the only reading left after that is a
            // 24-hour shift, which nobody meant to type.
            'start_time' => ['required_without:start_at', 'required_with:end_time', 'nullable', 'date_format:H:i'],
            'end_time' => ['required_without:end_at', 'required_with:start_time', 'nullable', 'date_format:H:i', 'different:start_time'],

            // The UTC pair has no midnight to cross — an instant is an instant
            // — so it is simply ordered.
            'start_at' => ['required_without:start_time', 'required_with:end_at', 'nullable', 'date'],
            'end_at' => ['required_without:end_time', 'required_with:start_at', 'nullable', 'date', 'after:start_at'],

            'unpaid_break_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Figure 22's 15 repeat values are unconfirmed, which is why the
            // column is a varchar and not an enum. A length cap is all that can
            // honestly be asserted here.
            'repeat_rule' => ['sometimes', 'string', 'max:32'],
            'repeat_until' => ['nullable', 'date_format:Y-m-d'],
            'series_id' => ['nullable', 'string', 'ulid'],
        ];
    }

    /**
     * The validated input as ShiftService::create() attributes.
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
            'unpaid_break_minutes',
            'notes',
            'repeat_rule',
            'repeat_until',
            'series_id',
        ]));

        if (($validated['start_time'] ?? null) !== null) {
            [$startLocal, $endLocal] = $this->localWindow(
                $validated['business_date'],
                $validated['start_time'],
                $validated['end_time'],
            );

            $attributes['start_at_local'] = $startLocal;
            $attributes['end_at_local'] = $endLocal;

            return $attributes;
        }

        $attributes['start_at'] = $validated['start_at'];
        $attributes['end_at'] = $validated['end_at'];

        return $attributes;
    }
}
