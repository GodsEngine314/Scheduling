<?php

namespace App\Http\Resources;

use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-store scheduling settings.
 *
 * @mixin StoreSetting
 */
class StoreSettingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'store_id' => (int) $this->store_id,
            'timezone' => $this->timezone,
            'publish_lead_days' => $this->publish_lead_days === null ? null : (int) $this->publish_lead_days,
            'auto_publish' => (bool) $this->auto_publish,

            // Whether a row actually exists, or these are the defaults every
            // reader falls back to. A caller writing a settings screen needs to
            // tell "configured as America/New_York" from "never configured".
            'configured' => (bool) $this->exists,
        ];
    }
}
