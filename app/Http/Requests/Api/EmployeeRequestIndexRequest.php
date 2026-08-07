<?php

namespace App\Http\Requests\Api;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** What is outstanding for this store — the manager's queue. */
class EmployeeRequestIndexRequest extends FormRequest
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

            'status' => ['sometimes', Rule::enum(RequestStatus::class)],
            'request_type' => ['sometimes', Rule::enum(RequestType::class)],
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],

            // A date range is all-or-nothing: half of one would silently mean
            // something different from what the caller wrote.
            'from' => ['sometimes', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
