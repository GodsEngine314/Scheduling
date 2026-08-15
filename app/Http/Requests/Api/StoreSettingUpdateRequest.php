<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing a store's settings.
 *
 * Every field is `sometimes`: a caller changing the lead days must not have to
 * resend the timezone, and a partial write must never blank the column it did
 * not mention.
 *
 * store_id is not accepted in the body — it is the {store} in the path, so
 * there is only one place it can come from and no way for the two to disagree.
 *
 * The `timezone` rule catches a zone PHP does not know, and
 * StoreSettingService checks it again. That duplication is deliberate: the
 * service is also reachable from the console and from a seeder, and a bad zone
 * reaching BusinessDay is a fatal on every screen rather than a validation error
 * on one.
 */
class StoreSettingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'timezone' => ['sometimes', 'string', 'timezone'],

            // How far ahead a publish run pushes when no explicit end is given.
            'publish_lead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],

            // Stored and read by nothing today. Accepted so a caller can set it
            // ahead of the feature, and documented as inert rather than
            // silently implying a schedule runs.
            'auto_publish' => ['sometimes', 'boolean'],

            'day_close_cutoff_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
