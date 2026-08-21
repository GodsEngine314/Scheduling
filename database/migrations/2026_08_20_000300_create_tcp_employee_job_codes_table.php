<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH JOB CODE TCP HAS ON FILE FOR EACH PERSON. SCHEDULING-OWNED, read from
 * GET /employeejobcodes.
 *
 * WHY THIS EXISTS. Every punch needs a jobCodeId, and until now we BUILT one:
 * a manager picked a position from a dropdown and TcpJobCodeRole::jobCodeIdFor()
 * assembled franchise+store+role into a code we hoped TCP had. That is guessing
 * with somebody's wages, and it failed in three separate ways in one week —
 * roles TCP has nowhere (Driver, Insider, Shift Lead), roles TCP has at one
 * store only (Management), and stores whose number cannot form a code at all.
 *
 * TCP already knows the answer. It assigns job codes to people, and the
 * assignment is what its own timeclock files hours against — the punch payload
 * carries both jobCodeId and employeeJobCodeRecordId. Reading the assignment
 * turns a guess into a lookup, and lets the dropdown go away entirely: nobody
 * should have to tell us what TCP could.
 *
 * WHAT THE LIVE DATA LOOKS LIKE. Every employee across two real stores (20 of
 * 20) carries exactly ONE per-store role code and no more:
 *
 *     37951001   "Crew Member - 3795-10"     <- the role. 8 digits.
 *     1003       "Bonus"                     <- a pay category. 4 digits.
 *
 * Both arrive on the same endpoint and only the SHAPE tells them apart, which
 * is why is_role is a stored column rather than a run-time strlen(): the
 * company-wide codes (1000 Regular, 1001 Training, 1002 Tipping, 1003 Bonus,
 * 2000 Sick, 3000/3001 penalties) name how an hour is PAID, not what somebody
 * did, and filing a punch as "Bonus" because it sorted first would be a payroll
 * error nobody would catch. Everything is stored, so the pay categories are
 * visible rather than silently dropped; only role rows are ever sent.
 *
 * NO FOREIGN KEY TO employees, on purpose, for the reason store_settings gives:
 * a scheduling-owned row must not be constrained by a replayable projection.
 * Rebuilding employees (truncate + replay) would either fail against the
 * constraint or, with a cascade, delete the mapping it was supposed to preserve.
 * tcp_employee_id is carried alongside so a re-sync never has to go through the
 * projection at all.
 *
 * NO position_id COLUMN either. role_suffix joins to tcp_job_code_roles, which
 * is where the suffix-to-position mapping already lives and is re-seeded from
 * the live catalogue; denormalising it here would go stale the first time that
 * seeder ran. Same reasoning as tcp_job_codes, which stores the suffix and
 * nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tcp_employee_job_codes', function (Blueprint $table) {
            $table->id();

            // OUR employee id. See the note above on why there is no FK.
            $table->unsignedBigInteger('employee_id');

            // TCP's, so a re-sync can be driven without reading the projection.
            $table->string('tcp_employee_id', 64);

            /**
             * The assignment's own record id — employeeJobCodeRecordId on a
             * punch payload. Stored because it is the only thing that identifies
             * the ASSIGNMENT rather than the code, and TCP may yet want it on a
             * write. Nothing sends it today.
             */
            $table->string('tcp_record_id', 64)->nullable();

            // The whole code as TCP spells it. A string: the leading digits are
            // structure, not magnitude, and '01' is not '1'.
            $table->string('job_code_id', 16);

            // TCP's label, e.g. "Crew Member - 3795-10". The estate's answer to
            // "what does 37951001 mean" without a join.
            $table->string('description')->nullable();

            /**
             * Set only for a per-store role code: franchise+store ("379510")
             * and the trailing role ("01"). NULL on a company-wide pay category,
             * which has neither.
             */
            $table->string('store_key', 8)->nullable()->index();
            $table->string('role_suffix', 8)->nullable();

            /**
             * Is this a role, or a pay category? See the note above — the
             * distinction decides what may be sent as a punch's jobCodeId, and
             * it is too important to re-derive from a string length at every
             * call site.
             */
            $table->boolean('is_role')->default(false);

            // When TCP last confirmed this assignment. A row that stops coming
            // back is an assignment that was removed there.
            $table->timestamp('tcp_synced_at')->nullable();

            $table->timestamps();

            // The upsert key: one row per person per code.
            $table->unique(['employee_id', 'job_code_id']);

            // THE LOOKUP the board and the writer both make: "the role code this
            // person holds at this store".
            $table->index(['employee_id', 'is_role', 'store_key'], 'tcp_emp_job_codes_role_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tcp_employee_job_codes');
    }
};
