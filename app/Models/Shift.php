<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\AvailabilityCheck;
use App\Enums\PublishState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PLANNED shifts — the schedule we build. SCHEDULING-OWNED.
 *
 * One row is one employee working one continuous block. A NULL employee_id is
 * an open shift; a split shift is two rows sharing a split_group_id.
 *
 * start_at / end_at are UTC. business_date is the store-local day the shift
 * belongs to and is stored rather than derived, because deriving it needs the
 * store's timezone every time.
 *
 * There is deliberately no cost column here: a cached estimate goes stale the
 * moment a pay rate changes or the shift moves. Cost is a query-time join
 * against employee_pay_histories.
 */
class Shift extends Model
{
    use SoftDeletes;

    protected $table = 'shifts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => DateOnly::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'repeat_until' => DateOnly::class,
            'split_part' => 'integer',
            'publish_state' => PublishState::class,
            'published_at' => 'datetime',
            'publish_attempts' => 'integer',
            'availability_check' => AvailabilityCheck::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function workSegments(): HasMany
    {
        return $this->hasMany(WorkSegment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Every part of this split shift, THIS ROW INCLUDED — the board draws the
     * whole group as one assignment. Empty when split_group_id is null.
     */
    public function splitParts(): HasMany
    {
        return $this->hasMany(self::class, 'split_group_id', 'split_group_id')
            ->orderBy('split_part');
    }

    /** The Figure 12/13 board: one store, one day, in clock order. */
    public function scopeForBoard(Builder $query, int $storeId, string $businessDate): Builder
    {
        // Plain where, not whereDate: business_date is already a DATE column and
        // wrapping it in DATE() would skip the (store_id, business_date) index.
        return $query->where('store_id', $storeId)
            ->where('business_date', $businessDate)
            ->orderBy('start_at');
    }

    /** The week view. */
    public function scopeForStoreBetween(Builder $query, int $storeId, string $from, string $to): Builder
    {
        return $query->where('store_id', $storeId)
            ->whereBetween('business_date', [$from, $to])
            ->orderBy('business_date')
            ->orderBy('start_at');
    }

    /** Placed on the board, nobody assigned yet. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('employee_id');
    }

    /**
     * Figure 25's delete/edit rules: omit the date for "all occurrences", pass
     * one for "this and following".
     */
    public function scopeInSeries(Builder $query, string $seriesId, ?string $fromBusinessDate = null): Builder
    {
        return $query->where('series_id', $seriesId)
            ->when($fromBusinessDate, fn (Builder $q) => $q->where('business_date', '>=', $fromBusinessDate));
    }

    /**
     * Paid hours: the block, less the unpaid break inside it.
     *
     * A split gap is NOT a break — it is the space between two rows — so it
     * never reaches this calculation.
     */
    public function paidHours(): float
    {
        if ($this->start_at === null || $this->end_at === null) {
            return 0.0;
        }

        // No break subtracted: a planned shift has none. Break time comes
        // only from TCP, on work_segments.break_minutes.
        $minutes = abs($this->start_at->diffInMinutes($this->end_at));

        return round(max($minutes, 0) / 60, 2);
    }

    public function isSplit(): bool
    {
        return $this->split_group_id !== null;
    }

    public function isOpen(): bool
    {
        return $this->employee_id === null;
    }
}
