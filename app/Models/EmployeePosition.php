<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PROJECTION. History, like EmployeeStoreAssignment. A shift's position is
 * chosen by the scheduler and is not constrained to what the employee holds
 * here — people cover roles they are not formally assigned to.
 */
class EmployeePosition extends Model
{
    protected $table = 'employee_positions';

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

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id');
    }
}
