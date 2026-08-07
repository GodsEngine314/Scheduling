<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk approval — the day-close workflow, where a manager signs off a screen of
 * hours at once.
 *
 * NOTE THE MISSING exists:work_segments,id. It is left off deliberately.
 * WorkSegmentService::approveMany() reports per-id outcomes — not_found,
 * open_punch, already_approved — so one stale id in a batch of forty comes back
 * as one skipped row and thirty-nine approvals. An exists: rule here would turn
 * that into a 422 for the whole batch and the manager would have to guess which
 * id was the problem.
 */
class WorkSegmentApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, int> */
    public function ids(): array
    {
        return array_map('intval', $this->validated('ids'));
    }
}
