<?php

namespace Database\Seeders;

use App\Enums\AvailabilityShiftType;
use App\Enums\DayOfWeek;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Enums\RequestDecision;
use App\Enums\RequestType;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Services\Scheduling\EmployeeRequestService;
use App\Services\Scheduling\ShiftService;
use App\Services\Scheduling\WorkSegmentService;
use App\Support\BusinessDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A day at one store, seeded to break in every way the schema was designed for.
 *
 * Deliberately built through ShiftService / WorkSegmentService / EmployeeRequestService
 * rather than by inserting rows: availability_check, business_date, reconciliation and
 * the request status cache are then computed by the real code, so the board shows what
 * the application actually decides — not what a fixture asserted.
 *
 * The scenario, and what each part is there to prove:
 *   Ada    split shift, two rows sharing a split_group_id, punched in for part 1 and
 *          still clocked in on part 2  -> open punch blocks the day close
 *   Ben    17 years old, scheduled past the end of his availability window
 *          -> outside_availability + minor, warned and still saved
 *   Cleo   21:00 -> 01:00 against a 20:00 -> 02:00 window that wraps midnight
 *          -> overnight shift attributed to the day it started
 *   Dov    approved time off covering today, yet punched in anyway with no shift
 *          -> unscheduled cover, and a conflict the board should surface
 *   plus   one open shift nobody is assigned to
 *
 * Availability is seeded for every weekday so the board is populated on any date
 * you navigate to, not just the seeded one.
 */
class DemoSeeder extends Seeder
{
    public const STORE_ID = 4821;

    public function run(): void
    {
        $businessDay = app(BusinessDay::class);
        $shifts = app(ShiftService::class);
        $segments = app(WorkSegmentService::class);
        $requests = app(EmployeeRequestService::class);

        DB::transaction(function () use ($businessDay, $shifts, $segments, $requests): void {
            $this->wipe();

            $manager = User::query()->create([
                'name' => 'Demo Manager',
                'email' => 'manager@store4821.test',
                'password' => Hash::make('password'),
            ]);

            // Store id is assigned by auth upstream, so it is written, not generated.
            $store = new Store(['store_number' => '4821']);
            $store->id = self::STORE_ID;
            $store->save();

            DB::table('store_settings')->insert([
                'store_id' => self::STORE_ID,
                'timezone' => 'America/New_York',
                'day_close_cutoff_time' => null,
                'publish_lead_days' => 14,
                'auto_publish' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $driver = Position::query()->create(['label' => 'Driver']);
            $insider = Position::query()->create(['label' => 'Insider']);
            $lead = Position::query()->create(['label' => 'Shift Lead']);

            BusinessDay::flushTimezoneCache();

            // Today in the store's own timezone — the board opens on a populated day.
            $today = $businessDay->toLocal(self::STORE_ID, now())->toDateString();

            $ada = $this->employee('Ada', 'Okafor', '2003-06-14', Gender::Female, $insider->id, [
                ['11:00', '14:00', AvailabilityShiftType::AM],
                ['17:00', '21:00', AvailabilityShiftType::PM],
            ], 17.50, 1.50);

            // Seventeen today, whenever today is — the minor warning must not rot.
            $ben = $this->employee('Ben', 'Ortiz', now()->subYears(17)->subMonths(4)->toDateString(),
                Gender::Male, $driver->id, [
                    ['16:00', '21:00', AvailabilityShiftType::PM],
                ], 15.00, 0.50);

            $cleo = $this->employee('Cleo', 'Nash', '1996-01-20', Gender::Female, $lead->id, [
                // to < from: this window wraps past midnight.
                ['20:00', '02:00', AvailabilityShiftType::OP],
            ], 19.00, 2.00);

            $dov = $this->employee('Dov', 'Reyes', '2001-11-09', Gender::Male, $driver->id, [
                ['11:00', '22:00', AvailabilityShiftType::AM],
            ], 16.00, 1.00);

            // ── planned ────────────────────────────────────────────────────
            $adaPart1 = $shifts->create([
                'store_id' => self::STORE_ID, 'employee_id' => $ada->id, 'position_id' => $insider->id,
                'start_at_local' => "{$today} 11:00:00", 'end_at_local' => "{$today} 14:00:00",
                'created_by_user_id' => $manager->id,
                'notes' => 'Lunch rush.',
            ]);
            $shifts->split(
                $adaPart1,
                $businessDay->combine(self::STORE_ID, $today, '17:00:00'),
                $businessDay->combine(self::STORE_ID, $today, '21:00:00'),
            );

            // Ends at 22:00 against a window that closes at 21:00, and he is 17.
            $benShift = $shifts->create([
                'store_id' => self::STORE_ID, 'employee_id' => $ben->id, 'position_id' => $driver->id,
                'start_at_local' => "{$today} 16:00:00", 'end_at_local' => "{$today} 22:00:00",
                'unpaid_break_minutes' => 30, 'created_by_user_id' => $manager->id,
            ]);

            // Crosses midnight, inside Cleo's wrapping window. The end is the
            // NEXT calendar day at 01:00; business_date must still resolve to
            // today, because a shift belongs to the day it started.
            $tomorrow = now()->parse($today)->addDay()->toDateString();
            $shifts->create([
                'store_id' => self::STORE_ID, 'employee_id' => $cleo->id, 'position_id' => $lead->id,
                'start_at_local' => "{$today} 21:00:00", 'end_at_local' => "{$tomorrow} 01:00:00",
                'created_by_user_id' => $manager->id, 'notes' => 'Close.',
            ]);

            $shifts->create([
                'store_id' => self::STORE_ID, 'employee_id' => null, 'position_id' => $driver->id,
                'start_at_local' => "{$today} 18:00:00", 'end_at_local' => "{$today} 22:00:00",
                'created_by_user_id' => $manager->id, 'notes' => 'Unfilled — needs a driver.',
            ]);

            // ── actual ─────────────────────────────────────────────────────
            $adaWorked = $segments->create([
                'store_id' => self::STORE_ID, 'employee_id' => $ada->id, 'position_id' => $insider->id,
                'time_in_local' => "{$today} 10:58:00", 'time_out_local' => "{$today} 14:02:00",
            ]);
            $segments->approve($adaWorked, $manager->id);

            // Still clocked in: no hours to approve, so the day cannot close.
            $segments->create([
                'store_id' => self::STORE_ID, 'employee_id' => $ada->id, 'position_id' => $insider->id,
                'time_in_local' => "{$today} 16:57:00", 'time_out_local' => null,
            ]);

            // Finished, nobody signed it off.
            $segments->create([
                'store_id' => self::STORE_ID, 'employee_id' => $ben->id, 'position_id' => $driver->id,
                'time_in_local' => "{$today} 16:02:00", 'time_out_local' => "{$today} 22:05:00",
                'break_minutes' => 30,
            ]);

            // Worked with nothing planned, while on approved leave.
            $segments->create([
                'store_id' => self::STORE_ID, 'employee_id' => $dov->id, 'position_id' => $driver->id,
                'time_in_local' => "{$today} 19:00:00", 'time_out_local' => "{$today} 21:00:00",
            ]);

            // ── requests ───────────────────────────────────────────────────
            $leave = $requests->create([
                'employee_id' => $dov->id, 'store_id' => self::STORE_ID,
                'request_type' => RequestType::TimeOff,
                'start_date' => $today,
                'end_date' => now()->parse($today)->addDays(2)->toDateString(),
                'description' => 'Family trip.',
                'requested_by_user_id' => $manager->id,
            ]);
            $requests->decide($leave, RequestDecision::Approved, $manager->id, 'Cover arranged.');

            $requests->create([
                'employee_id' => $ben->id, 'store_id' => self::STORE_ID,
                'request_type' => RequestType::AvailabilityChange,
                'description' => 'Can start at 15:00 once school term ends.',
                'requested_by_user_id' => $manager->id,
            ]);

            unset($benShift);
        });
    }

    /**
     * @param  array<int, array{0:string,1:string,2:AvailabilityShiftType}>  $windows
     */
    private function employee(
        string $first,
        string $last,
        string $birthDate,
        Gender $gender,
        int $positionId,
        array $windows,
        float $basePay,
        float $performancePay,
    ): Employee {
        $employee = Employee::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'gender' => $gender,
            'employment_type' => EmploymentType::W2,
            'primary_store_id' => self::STORE_ID,
            'primary_position_id' => $positionId,
            'primary_phone' => '+1-555-'.random_int(1000, 9999),
            'primary_email' => strtolower($first).'@store4821.test',
            'current_status' => EmployeeStatus::Hired,
            'current_status_effective_date' => now()->subMonths(8)->toDateString(),
            'hiring_updated_at' => now(),
        ]);

        $employee->storeAssignments()->create([
            'store_id' => self::STORE_ID,
            'effective_date' => now()->subMonths(8)->toDateString(),
        ]);

        $employee->positions()->create([
            'position_id' => $positionId,
            'effective_date' => now()->subMonths(8)->toDateString(),
        ]);

        $employee->payHistories()->create([
            'base_pay' => $basePay,
            'performance_pay' => $performancePay,
            'effective_date' => now()->subMonths(8)->toDateString(),
        ]);

        // Every weekday, so navigating to any date still shows availability.
        foreach (DayOfWeek::cases() as $day) {
            foreach ($windows as [$from, $to, $type]) {
                $employee->availabilityWindows()->create([
                    'day_of_week' => $day,
                    'available_from' => $from,
                    'available_to' => $to,
                    'shift_type' => $type,
                ]);
            }
        }

        return $employee;
    }

    /** Child rows first: the FKs are RESTRICT, not CASCADE, and rightly so. */
    private function wipe(): void
    {
        Shift::withTrashed()->forceDelete();
        DB::table('work_segments')->delete();
        DB::table('employee_request_decisions')->delete();
        EmployeeRequest::query()->delete();
        DB::table('employee_availability_windows')->delete();
        DB::table('employee_pay_histories')->delete();
        DB::table('employee_store_assignments')->delete();
        DB::table('employee_positions')->delete();
        DB::table('integration_identities')->delete();
        Employee::query()->delete();
        Position::query()->delete();
        DB::table('store_settings')->delete();
        Store::query()->delete();
        User::query()->delete();
    }
}
