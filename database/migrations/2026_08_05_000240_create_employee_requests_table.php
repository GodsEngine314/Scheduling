<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEDULING-OWNED. What employees ask the schedule to do.
 *
 * request_type + description alone cannot be acted on, so this carries the two
 * things a scheduler actually needs:
 *
 *   start_date / end_date — a time-off request without dates is a note, not a
 *   request. This is what makes approved time off visible to the schedule
 *   builder; otherwise the table is write-only.
 *
 *   status — cached from the latest row in employee_request_decisions, which is
 *   the audit trail. Denormalised on purpose: "show me pending requests for this
 *   store this week" is a board query and must not need a correlated subquery.
 *   Derived, so a decision write must always update both.
 *
 * shift_id is for the types that are about a specific shift (cover, claim, and
 * the employee-facing half of a swap). It is nullOnDelete for the same reason
 * work_segments.shift_id is: deleting a shift must not erase the request
 * history that referred to it.
 *
 * A swap has a second side and its own state machine in the workflow document
 * (pending -> accepted -> approved -> applied). That belongs in a dedicated
 * shift_swaps table, not here — this table records that someone asked.
 *
 * employee_id is the SUBJECT of the request; requested_by_user_id is whoever
 * entered it. Employees do not have logins in this system, so a manager filing
 * on someone's behalf is the normal case, and conflating the two would lose who
 * actually typed it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_requests', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete, NOT cascade. employee_requests and their
            // decision trail are SCHEDULING-OWNED; employees is a PROJECTION.
            // Under cascade, rebuilding the projection would silently delete
            // every time-off request and every decision ever recorded — the
            // exact coupling the projection/owned split exists to prevent.
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            $table->enum('request_type', [
                'time_off',
                'shift_swap',
                'cover_request',
                'open_shift_claim',
                'availability_change',
                'other',
            ]);

            $table->text('description')->nullable();

            // The period the request is about. Null for types that aren't
            // date-ranged (availability_change, other).
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();

            // Cached from the latest decision. See the note above.
            $table->enum('status', ['pending', 'approved', 'denied', 'cancelled'])->default('pending');

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            // The board query: what is outstanding for this store, this week.
            $table->index(['store_id', 'status', 'start_date']);
            // Conflict check when placing a shift: does this person have
            // approved time off covering it?
            $table->index(['employee_id', 'status', 'start_date', 'end_date'], 'emp_requests_conflict_index');
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_requests');
    }
};
