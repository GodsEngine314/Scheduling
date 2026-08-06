<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PROJECTION of the positions carried inside hiring.v1.employee.*.
 * The Humanity schedule id and TCP jobCodeId for a position live in
 * integration_identities, not here — a replay would wipe them.
 */
class Position extends Model
{
    protected $table = 'positions';

    protected $guarded = [];

    public function employeePositions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'primary_position_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function workSegments(): HasMany
    {
        return $this->hasMany(WorkSegment::class);
    }
}
