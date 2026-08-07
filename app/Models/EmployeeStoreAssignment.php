<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PROJECTION. History, not current state: "who belongs to this store" is the
 * latest row per employee by effective_date.
 */
class EmployeeStoreAssignment extends Model
{
    protected $table = 'employee_store_assignments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_date' => DateOnly::class,
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

    /** Assignments already in force on the given date, newest first. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id');
    }
}
