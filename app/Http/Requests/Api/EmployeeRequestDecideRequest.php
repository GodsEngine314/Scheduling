<?php

namespace App\Http\Requests\Api;

use App\Enums\RequestDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deciding a request.
 *
 * Every decision here settles it — there is no multi-step approval in this
 * table — and each one appends a row rather than editing the last, so a
 * reversal keeps both halves of the story.
 *
 * The decider is the acting user, taken in the controller and never from the
 * body.
 */
class EmployeeRequestDecideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(RequestDecision::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function decision(): RequestDecision
    {
        return RequestDecision::from((string) $this->validated('decision'));
    }
}
