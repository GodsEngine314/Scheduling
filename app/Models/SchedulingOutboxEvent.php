<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * SCHEDULING-OWNED. Carries scheduling.v1.* — shift published, hours approved,
 * day closed.
 *
 * Written inside the same DB::transaction as the state change it describes:
 * no published event without the row it reports, and no row silently
 * unpublished.
 */
class SchedulingOutboxEvent extends Model
{
    use HasUlids;

    protected $table = 'scheduling_outbox_events';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** What the publish sweep picks up. */
    public function scopeUnpublished(Builder $query): Builder
    {
        return $query->whereNull('published_at');
    }
}
