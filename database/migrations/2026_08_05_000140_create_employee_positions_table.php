<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECTION of the employee event's positions.position — rebuilt by replay.
 *
 * History, like employee_store_assignments. A shift's position_id is chosen
 * by the scheduler and is not constrained to the employee's held positions —
 * people cover roles they are not formally assigned to.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->date('effective_date');
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_positions');
    }
};
