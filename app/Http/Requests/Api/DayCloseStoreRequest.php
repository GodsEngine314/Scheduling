<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing the day.
 *
 * Bodies in this API use the column names, so this is store_id / business_date
 * where the GET is ?store=&date=.
 *
 * Nothing here checks whether the day is closable. That is DayCloseService's
 * question, it needs the work segments to answer it, and its refusal already
 * carries the blockers with the employees named — which is what the caller has
 * to be shown. A duplicate rule here could only disagree with it.
 */
class DayCloseStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'business_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
