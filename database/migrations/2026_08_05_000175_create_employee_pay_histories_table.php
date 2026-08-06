<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECTION of the employee event's payHistories — rebuilt by replay.
 *
 * Here for one purpose: estimating the labour cost of a schedule.
 * base_pay and performance_pay are hourly rates, so the estimate for a planned
 * shift is (paid hours) x (base_pay + performance_pay) at the rate in effect on
 * that shift's business_date.
 *
 * Kept as history rather than a current_pay column on employees precisely
 * because of that "in effect on" clause: costing last month's schedule at
 * today's rate gives the wrong number, and a rate change mid-period would
 * silently rewrite the past.
 *
 * The estimate is NOT stored on shifts. A cached cost column would go stale the
 * moment a rate changed or a shift moved; compute it in the query that renders
 * the week.
 *
 * This is the most sensitive data in the schema. Nothing at the database level
 * can protect it — gate it in the application: a shift manager needs to see a
 * store's total, not each colleague's hourly rate. Keep it out of API resources
 * and CSV exports by default.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_pay_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('base_pay', 10, 2);
            $table->decimal('performance_pay', 10, 2);
            $table->date('effective_date');
            $table->timestamps();

            // "The rate in effect for this employee on this date" — the only
            // query this table exists to answer.
            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_pay_histories');
    }
};
