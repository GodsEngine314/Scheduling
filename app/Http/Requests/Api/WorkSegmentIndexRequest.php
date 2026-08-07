<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** Actual worked hours: one store, one business date. */
class WorkSegmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date_format:Y-m-d'],

            // The two day-close views, as filters.
            'unapproved' => ['sometimes', 'boolean'],
            'open_punches' => ['sometimes', 'boolean'],
            'unmatched' => ['sometimes', 'boolean'],

            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
        ];
    }
}
