<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * SCHEDULING-OWNED. What employees ask the schedule to do.
 *
 * employee_id is the SUBJECT of the request; requested_by_user_id is whoever
 * typed it — employees have no logins here, so a manager filing on someone's
 * behalf is the normal case.
 *
 * status is CACHED from the latest decision so the board query needs no
 * correlated subquery. It is derived: write a decision and this column in the
 * same transaction, or the two drift.
 */
class EmployeeRequest extends Model
{
    protected $table = 'employee_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request_type' => RequestType::class,
            'status' => RequestStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(EmployeeRequestDecision::class);
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(EmployeeRequestDecision::class)->latestOfMany();
    }

    /**
     * The conflict check when placing a shift: is this person on approved time
     * off that day?
     */
    public function scopeApprovedTimeOffCovering(Builder $query, int $employeeId, string $date): Builder
    {
        return $query->where('employee_id', $employeeId)
            ->where('request_type', RequestType::TimeOff)
            ->where('status', RequestStatus::Approved)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Pending);
    }

    /** The board query: what is outstanding for this store, this week. */
    public function scopeForStoreBetween(Builder $query, int $storeId, string $from, string $to): Builder
    {
        return $query->where('store_id', $storeId)
            ->where('start_date', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from));
    }
}
