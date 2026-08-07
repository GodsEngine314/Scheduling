<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** The Figure 12/13 board: one store, one business date. */
class BoardIndexRequest extends FormRequest
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

            // ?include=cost adds the per-employee cost lines. The day's totals
            // are always present. See ApiController::wantsCost().
            'include' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z_,]+$/'],
        ];
    }
}
