<?php

namespace App\Http\Requests\Api;

use App\Enums\RequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting a request that has not been ruled on yet.
 *
 * Every field is `sometimes`: this is a correction, so sending only the
 * end_date must mean "change the end date", not "clear everything else".
 *
 * NOT ACCEPTED, and each for its own reason:
 *
 *   status                 a cache of the latest decision row. It moves through
 *                          /decide and nowhere else.
 *   employee_id            the SUBJECT. Changing it makes this a different
 *                          request rather than a corrected one.
 *   requested_by_user_id   who typed the original. Fixing somebody's typo does
 *                          not make the request yours.
 *
 * "Pending only" and the time-off date rule are EmployeeRequestService's, and
 * are left there — they depend on stored state a form request cannot see, and a
 * copy here could only disagree.
 */
class EmployeeRequestUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_type' => ['sometimes', Rule::enum(RequestType::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // after_or_equal only fires when BOTH arrive together. A request
            // sending one of them is checked against the stored other, which
            // only the service can see.
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],

            'shift_id' => ['sometimes', 'nullable', 'integer', 'exists:shifts,id'],
        ];
    }
}
