<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The roster for one store.
 *
 * Resigned and terminated people are excluded by default. Hiring publishes no
 * employee.deleted event — a termination arrives as an update carrying a new
 * status — so the row stays and the filter is a status filter, not a
 * row-exists check. include_inactive is for the manager looking back at who
 * worked a past week.
 */
class EmployeeIndexRequest extends FormRequest
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

            'include_inactive' => ['sometimes', 'boolean'],

            // ?include=cost adds each person's current hourly rate. See
            // ApiController::wantsCost().
            'include' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z_,]+$/'],
        ];
    }
}
