<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** The week view: one store, a range of business dates. */
class ShiftIndexRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],

            // ?include=cost. See ApiController::wantsCost().
            'include' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z_,]+$/'],
        ];
    }
}
