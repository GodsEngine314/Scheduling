<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PROJECTION. base_pay and performance_pay are HOURLY rates, kept as history
 * because a schedule must be costed at the rate in effect on its business_date,
 * not at today's.
 *
 * The most sensitive data in the schema. Gate it in the application: a shift
 * manager needs a store total, not a colleague's hourly rate. Keep it out of
 * API resources and exports by default.
 */
class EmployeePayHistory extends Model
{
    protected $table = 'employee_pay_histories';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'base_pay' => 'decimal:2',
            'performance_pay' => 'decimal:2',
            'effective_date' => DateOnly::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The rate in effect for one employee on one date — the only question this
     * table exists to answer. Ordered and limited, so take ->first().
     */
    public function scopeRateOn(Builder $query, int $employeeId, string $date): Builder
    {
        return $query->where('employee_id', $employeeId)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->limit(1);
    }

    /** What an hour of this employee's time costs at this rate. */
    public function hourlyRate(): float
    {
        return (float) $this->base_pay + (float) $this->performance_pay;
    }
}
