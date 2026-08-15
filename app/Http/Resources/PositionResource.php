<?php

namespace App\Http\Resources;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role somebody can be rostered as.
 *
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'label' => $this->label,
            'description' => $this->description,

            // The TCP jobCodeId and Humanity schedule id are NOT here. They are
            // scheduling's own discoveries, they live in integration_identities
            // so a replay cannot erase them, and a caller placing a shift has no
            // use for them — the publish and sync paths resolve them server-side.
        ];
    }
}
