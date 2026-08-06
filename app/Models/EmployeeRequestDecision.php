<?php

namespace App\Models;

use App\Enums\RequestDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCHEDULING-OWNED. The audit trail behind employee_requests.status.
 *
 * One row per decision, so a reversal ("approved, then withdrawn when the cover
 * fell through") keeps both halves. user_id is nullOnDelete: losing an auth
 * user must not delete the record that a decision was taken.
 */
class EmployeeRequestDecision extends Model
{
    protected $table = 'employee_request_decisions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decision' => RequestDecision::class,
            'completed_at' => 'datetime',
        ];
    }

    public function employeeRequest(): BelongsTo
    {
        return $this->belongsTo(EmployeeRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
