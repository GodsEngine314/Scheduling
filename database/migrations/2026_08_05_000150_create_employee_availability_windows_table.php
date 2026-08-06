<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECTION of the employee event's availabilityDays.times — rebuilt by replay.
 *
 * One row is one concrete window: "Monday, 16:00 to 21:00". Upstream this is two
 * tables (employee_availability_days -> employee_availability_times) and the day
 * is a hop away from the hours; the projection handler flattens them, because
 * the only question this data exists to answer is a single overlap test:
 *
 *   is [shift.start_at, shift.end_at] inside a window for that weekday?
 *
 * Several rows per day are expected and meaningful. Someone available 11:00-14:00
 * and again 17:00-21:00 has two rows, and that is exactly the shape a split shift
 * is checked against.
 *
 * OVERNIGHT WINDOWS are encoded by the ordering of the two columns, with no extra
 * flag to fall out of sync with them:
 *
 *   available_to >  available_from   same day        16:00 -> 21:00
 *   available_to <  available_from   wraps midnight  20:00 -> 02:00 (Tuesday)
 *   available_to == available_from   rejected, it is ambiguous between
 *                                    zero-length and a full 24 hours
 *
 * day_of_week always names the day the window STARTS on, so a wrapping window
 * belongs to the evening it began, not the morning it ended.
 *
 * shift_type is carried through from upstream as a descriptor only. AM/PM/OP is
 * how hiring categorises the window; the hours are what scheduling tests against.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_availability_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->enum('day_of_week', ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']);

            $table->time('available_from');
            $table->time('available_to');

            $table->enum('shift_type', ['AM', 'PM', 'OP'])->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'day_of_week']);

            // A replay re-inserts the same windows; this keeps it idempotent.
            $table->unique(
                ['employee_id', 'day_of_week', 'available_from', 'available_to'],
                'emp_availability_window_unique'
            );
        });

        // SQLite cannot ADD CONSTRAINT; production is MySQL. See the shifts migration.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE employee_availability_windows
                 ADD CONSTRAINT chk_availability_window CHECK (available_to <> available_from)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_availability_windows');
    }
};
