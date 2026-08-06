<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PLANNED shifts — the schedule we build. SCHEDULING-OWNED.
 *
 * "The whole scheduling will be handled on our platform until the user hit
 * publish." These rows are local and mutable; they only reach Humanity when a
 * publish run picks them up, and publish_state is what says where they are.
 *
 * One row per employee, not one row per time-slot-with-many-staff. It
 * reconciles 1:1 against work_segments (which are always per-employee), it
 * matches the per-employee board in the document's Figures 12/13, and it makes
 * the staffing delta on publish trivial.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            // NULL = an open shift: placed on the board before anyone is assigned.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->restrictOnDelete();

            // The store-local day this shift belongs to. Stored, not derived: a
            // shift starting 22:00 belongs to the day it started, and deriving
            // that from a UTC timestamp needs the store's timezone every time.
            $table->date('business_date');

            $table->dateTime('start_at'); // UTC
            $table->dateTime('end_at');   // UTC
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0);
            $table->text('notes')->nullable();

            // Figure 22 lists 15 repeat values. Kept as a validated string
            // rather than an enum until those values are confirmed — a wrong
            // enum needs an ALTER TABLE, a wrong string needs a config edit.
            $table->string('repeat_rule', 32)->default('none');
            $table->date('repeat_until')->nullable();

            // Ties every occurrence of one recurring series together, which is
            // what turns Figure 25's delete rules into a query:
            //   following => series_id = ? AND business_date >= ?
            //   all       => series_id = ?
            $table->ulid('series_id')->nullable();

            // SPLIT SHIFTS. One row is always one continuous block of work, so a
            // split shift is two rows sharing a split_group_id — 11:00-14:00 as
            // part 1, 17:00-21:00 as part 2.
            //
            // Not one row with a child table of blocks, because a row here maps
            // 1:1 onto a Humanity shift and 1:1 onto the punches that reconcile
            // against it. Keeping that alignment means publishing, matching and
            // editing need no special case for splits; only the board, which
            // draws the parts as one assignment, has to know.
            //
            // The gap between parts is implied by part 1's end_at and part 2's
            // start_at, and is NOT unpaid_break_minutes: a break is time inside
            // one block, a split gap is time between two of them. Conflating
            // them would inflate the paid hours of every split shift.
            $table->ulid('split_group_id')->nullable();
            $table->unsignedTinyInteger('split_part')->nullable();

            $table->enum('publish_state', ['draft', 'queued', 'published', 'failed', 'unpublished'])->default('draft');
            $table->string('humanity_shift_id', 64)->nullable()->unique();

            // SHA-256 of the payload Humanity last accepted. Re-publishing
            // sends only what changed; editing a shift nulls this.
            $table->char('payload_fingerprint', 64)->nullable();

            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->text('last_publish_error')->nullable();

            // Warns, never blocks. Recomputed against employee_availability_*
            // whenever the shift or the employee's availability changes.
            $table->enum('availability_check', ['ok', 'outside_availability', 'unknown'])->default('unknown');

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'business_date']);      // the Figure 12/13 board
            $table->index(['employee_id', 'business_date']);
            $table->index(['series_id', 'business_date']);      // delete rule=following
            $table->index(['publish_state', 'business_date']);  // the publish sweep
            $table->index(['split_group_id', 'split_part']);    // draw a split as one assignment
        });

        // No fluent Blueprint equivalent for a range check, and SQLite cannot
        // ADD CONSTRAINT after the fact. Production is MySQL (matching hiring);
        // on the SQLite test connection this invariant is enforced by the
        // application only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE shifts ADD CONSTRAINT chk_shifts_times CHECK (end_at > start_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
