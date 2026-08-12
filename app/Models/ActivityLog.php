<?php

namespace App\Models;

use App\Enums\ActivityAction;
use App\Enums\ActivitySubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change. Append-only — see the migration.
 *
 * $timestamps is false because the table has created_at and no updated_at: a
 * row here is never revised, and letting Eloquent try to write updated_at
 * would fail on the missing column.
 */
class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'subject_type' => ActivitySubject::class,
            'action' => ActivityAction::class,
            'business_date' => 'date',
            'changed_fields' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Nullable: the auth user may be gone. actor_name is the durable answer. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The panel: newest first, for one store. */
    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId)->orderByDesc('id');
    }

    /** What happened to a given date, or a date range on the week view. */
    public function scopeCoveringDates(Builder $query, string $from, ?string $to = null): Builder
    {
        return $query->whereBetween('business_date', [$from, $to ?? $from]);
    }

    /** Everything ever done to one row. */
    public function scopeForSubject(Builder $query, ActivitySubject $type, int $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    /**
     * "start_at 17:00 → 19:00, employee Ada Okafor → Ben Ortiz"
     *
     * Reads the stored diff rather than re-deriving anything: the row is the
     * record of what changed, and re-computing from current state would show
     * today's values on a year-old line.
     */
    public function summariseChanges(): string
    {
        $changes = $this->changed_fields ?? [];

        if ($changes === []) {
            return '';
        }

        return collect($changes)
            ->map(fn (array $change, string $field): string => sprintf(
                '%s %s → %s',
                $field,
                $this->readable($change['from'] ?? null),
                $this->readable($change['to'] ?? null),
            ))
            ->join(', ');
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
