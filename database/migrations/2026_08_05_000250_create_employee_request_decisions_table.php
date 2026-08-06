<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEDULING-OWNED. The audit trail behind employee_requests.status.
 *
 * Mirrors the separation_requests / separation_request_decisions pattern already
 * in HiringPizza, for the same reason: a status column overwrites itself, so a
 * reversal ("approved, then withdrawn when the cover fell through") leaves no
 * trace of the first decision or who made it. One row per decision keeps the
 * whole sequence.
 *
 * user_id is nullOnDelete rather than cascade — losing an auth user must not
 * delete the record that a decision was taken. The trail survives; only the
 * name is lost.
 *
 * Index names are set explicitly: the conventional Laravel name for this table
 * plus these columns lands within a couple of characters of MySQL's 64-byte
 * identifier limit. HiringPizza's separation_request_decisions migration does
 * the same thing for the same reason.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_request_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_request_id')->constrained('employee_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('decision', ['approved', 'denied', 'cancelled']);
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            $table->index(['employee_request_id', 'created_at'], 'emp_request_decision_request_created_index');
            $table->index(['user_id', 'created_at'], 'emp_request_decision_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_request_decisions');
    }
};
