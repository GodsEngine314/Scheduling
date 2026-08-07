<?php

namespace App\Http\Requests\Api;

use App\Enums\RequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing a request.
 *
 * status is not accepted. A request cannot be born approved — approval is a
 * decision, and a decision leaves a row behind in
 * employee_request_decisions. POST /api/employee-requests/{id}/decide is the
 * only way the status column ever moves.
 *
 * requested_by_user_id is not accepted either: it is read from the acting user
 * in the controller, because a caller who could name the filer could name
 * anyone.
 *
 * "A time-off request without dates is a note, not a request" is
 * EmployeeRequestService's rule and is left there — it is conditional on the
 * type, and a copy here could only disagree with it.
 */
class EmployeeRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The SUBJECT of the request, not whoever is typing it.
            'employee_id' => ['required', 'integer', 'exists:employees,id'],

            'request_type' => ['required', Rule::enum(RequestType::class)],
            'description' => ['nullable', 'string', 'max:2000'],

            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],

            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            // Optional: a request about a specific shift inherits that shift's
            // store, which the service works out.
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ];
    }
}
