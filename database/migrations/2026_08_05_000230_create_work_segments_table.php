<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACTUAL worked hours — our mirror of TCP work segments. SCHEDULING-OWNED.
 *
 * Written by the TCP sync, edited by managers (approve / correct times /
 * create for someone who forgot to clock in), and read by the day close:
 * "they cannot close the day until they approve all the hours of the day."
 *
 * Separate from shifts because the two disagree in every direction that
 * matters: a shift can go unworked, hours can arrive with no shift behind
 * them, and one shift can produce several punches.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_segments', function (Blueprint $table) {
            $table->id();

            // NULL until POST /worksegments succeeds. A segment created here for
            // an employee who forgot to clock in exists locally first, so a
            // failed create leaves a visible row rather than losing the hours.
            $table->string('tcp_segment_id', 64)->nullable()->unique();

            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            // The reconciliation link, and the reason there are two tables.
            // nullOnDelete, never cascade: deleting a planned shift must not
            // delete the record of hours somebody actually worked. This is the
            // one FK here that departs from the restrictOnDelete default.
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            // Distinguishes "the matcher paired these" from "a human insisted".
            $table->enum('match_source', ['unmatched', 'auto', 'manual'])->default('unmatched');

            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->restrictOnDelete();

            $table->date('business_date');
            $table->dateTime('time_in');

            // NULL is a real, expected state: an open punch. Someone clocked in
            // and has not left. It has no hours to approve, which is why the day
            // close has to report it separately instead of treating it as done.
            $table->dateTime('time_out')->nullable();

            $table->unsignedSmallInteger('break_minutes')->default(0);

            // As TCP reports it, not derived from time_in/time_out. When TCP's
            // number and ours disagree, payroll needs to see TCP's.
            $table->decimal('hours', 6, 2)->nullable();

            // Figure 14 filters these by string, not by id, so they stay strings.
            $table->string('cost_code_name')->nullable();
            $table->string('labor_code')->nullable();

            $table->text('notes')->nullable();

            $table->boolean('manager_approval')->default(false);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('employee_approval')->default(false);

            // The document's Create Shift workflow produces rows nobody punched.
            $table->enum('origin', ['tcp_sync', 'manual_create'])->default('tcp_sync');

            // The Change Shift workflow. A time correction clears
            // manager_approval, so the schema records that it happened and who
            // did it — otherwise a segment stays "approved" for hours nobody
            // reviewed.
            $table->timestamp('times_corrected_at')->nullable();
            $table->foreignId('times_corrected_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Drives the incremental sync (--minutes) off TCP's updatedOn.
            $table->timestamp('tcp_updated_on')->nullable();
            $table->timestamp('tcp_synced_at')->nullable();

            // The raw response. The workflow document's field tables are images
            // that could not be read, so this is the safety net: nothing in a
            // real payload is lost while the column mapping is unconfirmed.
            $table->json('tcp_payload')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'business_date']);
            $table->index(['employee_id', 'time_in']);
            // Total hours for one person on one day. With split shifts and
            // lunch breaks that spans several rows, and daily-hours and
            // overtime rules are asked of the day, not of a single shift.
            $table->index(['employee_id', 'business_date']);
            $table->index(['store_id', 'business_date', 'time_out']);          // open punches
            $table->index(['store_id', 'business_date', 'manager_approval']);  // the close gate
            $table->index('tcp_updated_on');
        });

        // See the note in the shifts migration: MySQL only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE work_segments ADD CONSTRAINT chk_segments_times CHECK (time_out IS NULL OR time_out > time_in)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_segments');
    }
};
