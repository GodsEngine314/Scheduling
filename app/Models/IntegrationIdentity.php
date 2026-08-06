<?php

namespace App\Models;

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSyncState;
use App\Enums\IntegrationSystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SCHEDULING-OWNED. The map between our projected entities and their ids in TCP
 * and Humanity.
 *
 * Deliberately has no relations: entity_id is polymorphic across three
 * projections and carries no foreign key. If a Humanity id lived on a
 * projection, a replay would wipe it and the next publish run would create
 * every shift in Humanity a second time.
 */
class IntegrationIdentity extends Model
{
    protected $table = 'integration_identities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entity_type' => IntegrationEntityType::class,
            'system' => IntegrationSystem::class,
            'sync_state' => IntegrationSyncState::class,
            'synced_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function scopeForEntity(
        Builder $query,
        IntegrationEntityType $entityType,
        int $entityId,
        ?IntegrationSystem $system = null,
    ): Builder {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->when($system, fn (Builder $q) => $q->where('system', $system));
    }

    /** Look an entity up from the remote id we already hold. */
    public function scopeForExternalId(
        Builder $query,
        IntegrationSystem $system,
        IntegrationEntityType $entityType,
        string $externalId,
    ): Builder {
        return $query->where('system', $system)
            ->where('entity_type', $entityType)
            ->where('external_id', $externalId);
    }

    /**
     * What a retry command selects on: the remote create never landed.
     */
    public function scopeNeedsSync(Builder $query): Builder
    {
        return $query->whereIn('sync_state', [
            IntegrationSyncState::Pending->value,
            IntegrationSyncState::Failed->value,
        ])->whereNull('external_id');
    }

    public function isSynced(): bool
    {
        return $this->sync_state === IntegrationSyncState::Synced && $this->external_id !== null;
    }
}
