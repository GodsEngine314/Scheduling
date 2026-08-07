<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deleting a shift, with Figure 25's series rules.
 *
 * The default is 'following' — this occurrence and every later one — because it
 * is the survivable mistake. 'all' wipes past occurrences too and has to be
 * asked for by name.
 */
class ShiftDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rule' => ['sometimes', Rule::in(['following', 'all'])],
        ];
    }

    public function seriesRule(): string
    {
        return (string) $this->validated('rule', 'following');
    }
}
