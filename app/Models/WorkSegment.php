<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\MatchSource;
use App\Enums\SegmentOrigin;
use App\Enums\TcpSyncState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ACTUAL worked hours — our mirror of TCP work segments. SCHEDULING-OWNED.
 *
 * Separate from shifts because the two disagree in every direction that
 * matters: a shift can go unworked, hours can arrive with no shift behind them,
 * and one shift can produce several punches.
 *
 * hours is TCP's number, not ours. When TCP's figure and a time_in/time_out
 * subtraction disagree, payroll needs TCP's.
 */
class WorkSegment extends Model
{
    use SoftDeletes;

    protected $table = 'work_segments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'match_source' => MatchSource::class,
            'origin' => SegmentOrigin::class,
            'tcp_sync_state' => TcpSyncState::class,
            'business_date' => DateOnly::class,
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'break_minutes' => 'integer',
            'hours' => 'decimal:2',
            'manager_approval' => 'boolean',
            'employee_approval' => 'boolean',
            'approved_at' => 'datetime',
            'times_corrected_at' => 'datetime',
            'tcp_updated_on' => 'datetime',
            'tcp_synced_at' => 'datetime',
            'tcp_payload' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function timesCorrectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'times_corrected_by_user_id');
    }

    public function scopeForBoard(Builder $query, int $storeId, string $businessDate): Builder
    {
        // Plain where keeps the (store_id, business_date) index in play.
        return $query->where('store_id', $storeId)
            ->where('business_date', $businessDate)
            ->orderBy('time_in');
    }

    /**
     * Clocked in, not yet out. A real, expected state — and one the day close
     * has to report separately, because there are no hours to approve yet.
     */
    public function scopeOpenPunches(Builder $query): Builder
    {
        return $query->whereNull('time_out');
    }

    /** The day-close gate: finished hours nobody has signed off. */
    public function scopeUnapproved(Builder $query): Builder
    {
        return $query->where('manager_approval', false)
            ->whereNotNull('time_out');
    }

    /** Punches with no planned shift behind them. */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('shift_id');
    }

    public function isOpenPunch(): bool
    {
        return $this->time_out === null;
    }
}
