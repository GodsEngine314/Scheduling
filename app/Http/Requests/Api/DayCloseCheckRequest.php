<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** "Can this store close this day yet, and if not, who is holding it up?" */
class DayCloseCheckRequest extends FormRequest
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
        ];
    }
}
