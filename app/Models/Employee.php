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

    /**
     * The role HIRING says this person holds, on a given date.
     *
     * WHERE A ROLE ACTUALLY COMES FROM. Scheduling used to ask a manager to pick
     * one per shift, which made the same fact answerable three different ways
     * for the same person on the same day. It is not scheduling's fact: hiring
     * owns who somebody is employed as, publishes it on
     * hiring.v1.employee.created|updated, and is where a promotion is recorded.
     * This service reads it and never writes it — a change belongs in hiring,
     * and the next event would overwrite anything written here anyway.
     *
     * EFFECTIVE-DATED, not current: employee_positions is history, so a shift
     * next month after a promotion gets the new role and last month's shifts
     * keep the old one. primary_position_id is the fallback the projection
     * carries for people whose history has not arrived — the board default, as
     * this class's own docblock says.
     *
     * Null is a real answer and means hiring has said nothing about this person
     * yet. It is not an invitation to guess: see BoardController's
     * plannedPositionId(), which asks TCP next and then stops.
     */
    public function positionIdOn(?string $date = null): ?int
    {
        $date ??= now()->toDateString();

        $historical = $this->positions()->effectiveOn($date)->value('position_id');

        if ($historical !== null) {
            return (int) $historical;
        }

        return $this->primary_position_id === null ? null : (int) $this->primary_position_id;
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
