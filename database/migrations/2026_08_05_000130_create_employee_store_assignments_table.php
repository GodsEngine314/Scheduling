<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECTION of the employee event's stores.store — rebuilt by replay.
 *
 * This is history, not current state. "Who belongs to this store" is the
 * latest row per employee by effective_date, which is what the dashboard's
 * per-store employee filter resolves against.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_store_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('effective_date');
            $table->timestamps();

            $table->index(['store_id', 'effective_date']);
            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_store_assignments');
    }
};
