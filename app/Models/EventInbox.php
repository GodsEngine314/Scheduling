<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SCHEDULING-OWNED. Column-for-column HiringPizza's event_inbox, so its
 * JetStreamConsumer ports across unedited.
 *
 * Scheduling consumes two streams here: hiring.v1.> and auth.v1.>. event_id is
 * the CloudEvent ULID and is what makes redelivery idempotent.
 */
class EventInbox extends Model
{
    protected $table = 'event_inbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'parked_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** Delivered, not yet handled, not parked for a human. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('processed_at')->whereNull('parked_at');
    }

    public function scopeParked(Builder $query): Builder
    {
        return $query->whereNotNull('parked_at');
    }
}
