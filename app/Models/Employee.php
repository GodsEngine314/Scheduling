<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PROJECTION of hiring.v1.employee.created|updated. Flat, one row per employee,
 * DERIVED — any write here is overwritten by the next event.
 *
 * primary_store_id / primary_position_id are the board default only; the full
 * sets live in storeAssignments and positions.
 */
class Employee extends Model
{
    protected $table = 'employees';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date' => DateOnly::class,
            'gender' => Gender::class,
            'employment_type' => EmploymentType::class,
            'current_status' => EmployeeStatus::class,
            'current_status_effective_date' => DateOnly::class,
            'hiring_updated_at' => 'datetime',
        ];
    }

    public function primaryStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'primary_store_id');
    }

    public function primaryPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'primary_position_id');
    }

    public function storeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeStoreAssignment::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class);
    }

    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(EmployeeAvailabilityWindow::class);
    }

    public function payHistories(): HasMany
    {
        return $this->hasMany(EmployeePayHistory::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function workSegments(): HasMany
    {
        return $this->hasMany(WorkSegment::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class);
    }

    /**
     * Hiring publishes no employee.deleted event — a termination arrives as an
     * update carrying a new status — so schedulability is a status filter, not
     * a row-exists check.
     */
    public function scopeSchedulable(Builder $query): Builder
    {
        return $query->whereIn('current_status', EmployeeStatus::schedulableValues());
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('primary_store_id', $storeId);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }
}
