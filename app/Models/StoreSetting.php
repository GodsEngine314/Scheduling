<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCHEDULING-OWNED. A stream replay must never touch this table.
 *
 * timezone is the load-bearing column: it turns a UTC start_at into a
 * business_date, decides which day an overnight shift belongs to, and makes
 * "close the day" mean the same thing in two states. auth's store events do not
 * carry it, so it cannot live on the stores projection.
 *
 * There is NO foreign key behind store() — constraining a scheduling-owned row
 * to a replayable one is exactly what this table avoids. Orphans are a
 * maintenance report, not a cascade.
 */
class StoreSetting extends Model
{
    protected $table = 'store_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'publish_lead_days' => 'integer',
            'auto_publish' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
