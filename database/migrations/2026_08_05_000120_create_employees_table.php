<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECTION of hiring.v1.employee.created|updated — rebuilt by replay.
 *
 * A flat, one-row-per-employee read model, derived from six upstream tables so
 * the schedule board is a single indexed query rather than six joins. It is
 * DERIVED, never hand-edited: any write here is overwritten by the next event.
 *
 * birth_date, NOT age. Age is derived and silently rots — every row is wrong on
 * the employee's birthday. It also cannot answer the question that actually
 * matters for scheduling: minor labour rules turn on the employee's age ON THE
 * DATE OF THE SHIFT, which only a date of birth can give you.
 * Upstream it lives on employee_obsessions, not employees.
 *
 * Deliberately NOT projected, though all of it arrives in the payload:
 *   - ssn                      hidden upstream; never in the payload
 *   - race, religion           protected-class attributes. This is the system
 *                              that decides who works when; holding them here
 *                              is discrimination exposure for no benefit.
 *   - t_shirt, image_path      no scheduling use
 *   - street address           no documented workflow reads it
 *   - emergency contacts       an HR concern, not a rostering one
 *   - bank account / routing   hidden and encrypted upstream
 *
 * primary_store_id / primary_position_id are the DEFAULT for the board only.
 * Upstream allows an employee to hold several positions and cover several
 * stores at once, so the full sets stay in employee_store_assignments and
 * employee_positions. Losing the upstream history is safe: shifts.store_id and
 * work_segments.store_id already record where each shift actually happened, so
 * past attribution never depends on this row.
 *
 * tcp_employee_id / tcp_employee_record_id are folded in rather than kept in a
 * separate identifiers table: they are single-valued per employee, and they
 * come from hiring (employee_ids / id_types), so they are replayable like
 * everything else here. Without them scheduling cannot call the TCP
 * work-segment API at all.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);

            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->enum('employment_type', ['W2', '1099']);

            // Default store / position for the board. See the note above.
            $table->unsignedBigInteger('primary_store_id')->nullable();
            $table->unsignedBigInteger('primary_position_id')->nullable();

            // Enough to tell someone their shift moved. Nothing more.
            $table->string('primary_phone', 40)->nullable();
            $table->string('primary_email')->nullable();

            // Hiring publishes no employee.deleted event — a termination arrives
            // as employee.updated carrying a new status history row — so
            // "is this person schedulable?" is answered from a column here
            // rather than from the row's absence.
            $table->enum('current_status', ['hired', 'resigned', 'terminated', 'rehired', 'OJE'])->nullable();
            $table->date('current_status_effective_date')->nullable();

            // From hiring's employee_ids / id_types. Hiring creates the TCP
            // employee and owns these values; we only mirror them.
            $table->string('tcp_employee_id', 64)->nullable();
            $table->string('tcp_employee_record_id', 64)->nullable();

            // Source-side updated_at, so a late-delivered event cannot overwrite
            // a newer projection.
            $table->timestamp('hiring_updated_at')->nullable();

            $table->timestamps();

            $table->foreign('primary_store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('primary_position_id')->references('id')->on('positions')->nullOnDelete();

            $table->unique('tcp_employee_id');
            $table->index('current_status');
            $table->index(['last_name', 'first_name']);
            $table->index(['primary_store_id', 'current_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
