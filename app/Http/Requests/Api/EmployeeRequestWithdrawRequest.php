<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdrawing a request.
 *
 * A DECISION, NOT A DELETE — it appends a cancelled row to
 * employee_request_decisions the same way an approval appends an approved one,
 * so the trail still answers "who asked, and what happened".
 *
 * There is no `decision` field to send: this endpoint exists precisely because
 * "withdraw" is a fixed one. Cancelling through /decide is still possible and
 * means the same thing; this is the version an employee-facing client can call
 * without being handed the whole decision vocabulary.
 *
 * The canceller is the acting user, taken in the controller, never from the body.
 */
class EmployeeRequestWithdrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
