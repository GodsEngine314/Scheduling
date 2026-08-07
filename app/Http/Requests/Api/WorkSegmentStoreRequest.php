<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ResolvesLocalWindow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The "forgot to clock in" workflow: a manager entering hours nobody punched.
 *
 * TWO WAYS TO SAY WHEN, as with shifts, and they may not be mixed:
 *
 *   business_date + clock_in + clock_out — store-local wall clock, H:i. A
 *   clock_out at or before clock_in is a punch that ran past midnight and rolls
 *   onto the next day rather than being refused.
 *
 *   time_in + time_out — UTC instants.
 *
 * A MISSING CLOCK-OUT IS NOT A MISSING FIELD. Omitting it creates an OPEN
 * PUNCH: somebody is on the clock and has not left. That is a real state the
 * day close reports separately, so it is nullable here rather than required.
 *
 * origin, manager_approval, tcp_segment_id and match_source are not accepted.
 * WorkSegmentService sets them: a segment created here is manual_create and
 * unapproved, and tcp_segment_id stays null until a push to TCP succeeds — a
 * failed push has to leave visible hours behind, not lose them.
 */
class WorkSegmentStoreRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],

            // Naming a shift is a human insisting on a pairing; the service
            // records it as match_source = manual and the matcher leaves it be.
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],

            'business_date' => ['required_with:clock_in', 'nullable', 'date_format:Y-m-d'],
            // clock_out before clock_in is a punch that ran past midnight and
            // is accepted. clock_out EQUAL to clock_in is not: it would resolve
            // to a 24-hour punch, which is a typo rather than a shift.
            'clock_in' => ['required_without:time_in', 'nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i', 'different:clock_in'],
            'time_in' => ['required_without:clock_in', 'nullable', 'date'],
            'time_out' => ['nullable', 'date', 'after:time_in'],

            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'cost_code_name' => ['nullable', 'string', 'max:255'],
            'labor_code' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'employee_approval' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated input as WorkSegmentService::create() attributes.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $validated = $this->validated();

        $attributes = array_intersect_key($validated, array_flip([
            'employee_id',
            'store_id',
            'position_id',
            'shift_id',
            'break_minutes',
            'cost_code_name',
            'labor_code',
            'notes',
            'employee_approval',
        ]));

        // business_date is deliberately NOT passed on: the service re-derives it
        // from time_in, because which day a punch belongs to is a timezone
        // question and the day close groups on the answer.
        if (($validated['clock_in'] ?? null) === null) {
            $attributes['time_in'] = $validated['time_in'];
            $attributes['time_out'] = $validated['time_out'] ?? null;

            return $attributes;
        }

        $localDate = $validated['business_date'];
        $clockOut = $validated['clock_out'] ?? null;

        if ($clockOut === null) {
            $attributes['time_in_local'] = $this->localMoment($localDate, $validated['clock_in']);

            return $attributes;
        }

        [$inLocal, $outLocal] = $this->localWindow($localDate, $validated['clock_in'], $clockOut);

        $attributes['time_in_local'] = $inLocal;
        $attributes['time_out_local'] = $outLocal;

        return $attributes;
    }
}
