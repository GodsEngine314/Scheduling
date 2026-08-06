<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Schema guards
|--------------------------------------------------------------------------
|
| These assert the shapes the schema deliberately allows and deliberately
| forbids. They use the DB facade rather than models on purpose: the subject
| under test is the migration, not an Eloquent layer that does not exist yet.
|
| Note on the range checks (end_at > start_at, time_out > time_in): those are
| MySQL-only, added via ALTER TABLE, because SQLite cannot ADD CONSTRAINT. The
| test connection is SQLite, so they are skipped here and covered once the
| MySQL connection is available.
|
*/

function makeStore(int $id = 1): int
{
    DB::table('stores')->insert([
        'id' => $id,
        'store_number' => 'S'.$id,
    ]);

    return $id;
}

function makeEmployee(string $last = 'Tester', array $overrides = []): int
{
    return DB::table('employees')->insertGetId(array_merge([
        'first_name' => 'Ada',
        'last_name' => $last,
        'employment_type' => 'W2',
        'current_status' => 'hired',
        'birth_date' => '1998-03-04',
        'gender' => 'female',
    ], $overrides));
}

function makeRequest(int $employee, array $overrides = []): int
{
    return DB::table('employee_requests')->insertGetId(array_merge([
        'employee_id' => $employee,
        'request_type' => 'time_off',
        'start_date' => '2026-08-10',
        'end_date' => '2026-08-12',
        'status' => 'pending',
    ], $overrides));
}

function makeShift(array $overrides = []): int
{
    return DB::table('shifts')->insertGetId(array_merge([
        'store_id' => 1,
        'business_date' => '2026-08-10',
        'start_at' => '2026-08-10 13:00:00',
        'end_at' => '2026-08-10 21:00:00',
    ], $overrides));
}

function makeSegment(array $overrides = []): int
{
    return DB::table('work_segments')->insertGetId(array_merge([
        'store_id' => 1,
        'business_date' => '2026-08-10',
        'time_in' => '2026-08-10 13:02:00',
        'time_out' => '2026-08-10 21:04:00',
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Shapes that must be allowed
|--------------------------------------------------------------------------
*/

it('allows an open shift with no employee assigned', function () {
    makeStore();
    $id = makeShift(['employee_id' => null]);

    expect(DB::table('shifts')->find($id)->employee_id)->toBeNull();
});

it('allows an open punch with no clock-out', function () {
    makeStore();
    $employee = makeEmployee();

    $id = makeSegment(['employee_id' => $employee, 'time_out' => null]);

    expect(DB::table('work_segments')->find($id)->time_out)->toBeNull();
});

it('allows worked hours with no scheduled shift behind them', function () {
    makeStore();
    $employee = makeEmployee();

    $id = makeSegment(['employee_id' => $employee, 'shift_id' => null]);

    $row = DB::table('work_segments')->find($id);
    expect($row->shift_id)->toBeNull()
        ->and($row->match_source)->toBe('unmatched');
});

it('allows several segments against one shift', function () {
    makeStore();
    $employee = makeEmployee();
    $shift = makeShift(['employee_id' => $employee]);

    // Clocked out for lunch and back in — one plan, two punches.
    makeSegment(['employee_id' => $employee, 'shift_id' => $shift, 'time_in' => '2026-08-10 13:02:00', 'time_out' => '2026-08-10 17:00:00']);
    makeSegment(['employee_id' => $employee, 'shift_id' => $shift, 'time_in' => '2026-08-10 17:30:00', 'time_out' => '2026-08-10 21:04:00']);

    expect(DB::table('work_segments')->where('shift_id', $shift)->count())->toBe(2);
});

it('keeps worked hours when the shift they were planned against is deleted', function () {
    makeStore();
    $employee = makeEmployee();
    $shift = makeShift(['employee_id' => $employee]);

    makeSegment(['employee_id' => $employee, 'shift_id' => $shift]);
    makeSegment(['employee_id' => $employee, 'shift_id' => $shift, 'time_in' => '2026-08-10 17:30:00', 'time_out' => '2026-08-10 21:04:00']);

    // A hard delete: this is what exercises the FK rule. An Eloquent soft
    // delete leaves the row in place, so shift_id keeps pointing at it and the
    // reconciliation survives — which is the behaviour we want day to day.
    DB::table('shifts')->where('id', $shift)->delete();

    expect(DB::table('work_segments')->count())->toBe(2)
        ->and(DB::table('work_segments')->whereNull('shift_id')->count())->toBe(2);
});

it('allows an unsynced integration identity with no external id yet', function () {
    $id = DB::table('integration_identities')->insertGetId([
        'entity_type' => 'employee',
        'entity_id' => 1,
        'system' => 'humanity',
        'external_id' => null,
        'sync_state' => 'failed',
        'last_error' => 'no humanity employee id',
    ]);

    // Repeated NULL external_ids must not collide, or one failure would block
    // every other pending mapping.
    DB::table('integration_identities')->insert([
        'entity_type' => 'employee',
        'entity_id' => 2,
        'system' => 'humanity',
        'external_id' => null,
        'sync_state' => 'pending',
    ]);

    expect(DB::table('integration_identities')->whereNull('external_id')->count())->toBe(2)
        ->and(DB::table('integration_identities')->find($id)->sync_state)->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| Shapes that must be refused
|--------------------------------------------------------------------------
*/

it('refuses worked hours for an employee who does not exist', function () {
    makeStore();

    expect(fn () => makeSegment(['employee_id' => 9999]))
        ->toThrow(QueryException::class);
});

it('refuses a shift at a store that does not exist', function () {
    expect(fn () => makeShift(['store_id' => 9999]))
        ->toThrow(QueryException::class);
});

it('refuses two mappings of the same entity to the same system', function () {
    $row = [
        'entity_type' => 'position',
        'entity_id' => 7,
        'system' => 'humanity',
        'external_id' => '4821',
    ];

    DB::table('integration_identities')->insert($row);

    expect(fn () => DB::table('integration_identities')->insert($row))
        ->toThrow(QueryException::class);
});

it('refuses two shifts claiming the same humanity shift id', function () {
    makeStore();
    makeShift(['humanity_shift_id' => 'HS-1001']);

    expect(fn () => makeShift(['humanity_shift_id' => 'HS-1001']))
        ->toThrow(QueryException::class);
});

it('refuses two work segments claiming the same tcp segment id', function () {
    makeStore();
    $employee = makeEmployee();
    makeSegment(['employee_id' => $employee, 'tcp_segment_id' => 'WS-500']);

    expect(fn () => makeSegment(['employee_id' => $employee, 'tcp_segment_id' => 'WS-500']))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| The two questions the workflow document actually asks
|--------------------------------------------------------------------------
*/

it('answers the day-close question: unapproved hours and open punches', function () {
    makeStore();
    $approved = makeEmployee('Approved');
    $unapproved = makeEmployee('Unapproved');
    $stillIn = makeEmployee('StillIn');

    makeSegment(['employee_id' => $approved, 'manager_approval' => true, 'approved_at' => now()]);
    makeSegment(['employee_id' => $unapproved, 'manager_approval' => false]);
    makeSegment(['employee_id' => $stillIn, 'time_out' => null]);

    $blockers = DB::table('work_segments')
        ->where('store_id', 1)
        ->where('business_date', '2026-08-10')
        ->where(fn ($q) => $q->where('manager_approval', false)->orWhereNull('time_out'))
        ->pluck('employee_id');

    // An open punch has no hours to approve, so it must be reported as its own
    // blocker rather than counted as done.
    expect($blockers)->toHaveCount(2)
        ->and($blockers->all())->toContain($unapproved, $stillIn)
        ->and($blockers->all())->not->toContain($approved);
});

it('answers the board question: scheduled versus actually present', function () {
    makeStore();
    $working = makeEmployee('Working');
    $noShow = makeEmployee('NoShow');
    $unscheduled = makeEmployee('Unscheduled');

    $workingShift = makeShift(['employee_id' => $working, 'publish_state' => 'published']);
    makeShift(['employee_id' => $noShow, 'publish_state' => 'published']);

    makeSegment(['employee_id' => $working, 'shift_id' => $workingShift, 'match_source' => 'auto', 'time_out' => null]);
    makeSegment(['employee_id' => $unscheduled, 'shift_id' => null, 'time_out' => null]);

    $scheduled = DB::table('shifts')
        ->where('store_id', 1)->where('business_date', '2026-08-10')
        ->whereNull('deleted_at')
        ->pluck('employee_id');

    $present = DB::table('work_segments')
        ->where('store_id', 1)->where('business_date', '2026-08-10')
        ->whereNull('time_out')
        ->pluck('employee_id');

    expect($scheduled->all())->toContain($working, $noShow)
        ->and($present->all())->toContain($working, $unscheduled)
        // Scheduled but not present.
        ->and($scheduled->diff($present)->all())->toContain($noShow)
        // Present but not scheduled.
        ->and($present->diff($scheduled)->all())->toContain($unscheduled);
});

/*
|--------------------------------------------------------------------------
| Employee snapshot, pay history and requests
|--------------------------------------------------------------------------
*/

it('stores a birth date rather than an age', function () {
    $id = makeEmployee('Minor', ['birth_date' => '2009-09-01']);

    // The question the schema has to answer is age ON THE SHIFT DATE, which an
    // age column cannot do. Both of these are true of the same row.
    $dob = new DateTimeImmutable(DB::table('employees')->find($id)->birth_date);

    expect($dob->diff(new DateTimeImmutable('2026-08-10'))->y)->toBe(16)
        ->and($dob->diff(new DateTimeImmutable('2027-09-02'))->y)->toBe(18);
});

it('keeps an employee schedulable at a second store', function () {
    makeStore(1);
    makeStore(2);
    $employee = makeEmployee('Cover', ['primary_store_id' => 1]);

    DB::table('employee_store_assignments')->insert([
        ['employee_id' => $employee, 'store_id' => 1, 'effective_date' => '2026-01-01'],
        ['employee_id' => $employee, 'store_id' => 2, 'effective_date' => '2026-06-01'],
    ]);

    // The primary is the board default; the bridge is what makes a shift at
    // store 2 legitimate rather than a data error.
    expect(DB::table('employees')->find($employee)->primary_store_id)->toBe(1)
        ->and(DB::table('employee_store_assignments')->where('employee_id', $employee)->pluck('store_id')->all())
        ->toBe([1, 2]);
});

it('finds the pay rate in effect on a given date', function () {
    $employee = makeEmployee('Raised');

    DB::table('employee_pay_histories')->insert([
        ['employee_id' => $employee, 'base_pay' => 15.00, 'performance_pay' => 1.00, 'effective_date' => '2026-01-01'],
        ['employee_id' => $employee, 'base_pay' => 17.50, 'performance_pay' => 1.50, 'effective_date' => '2026-07-01'],
    ]);

    $rateOn = fn (string $date) => DB::table('employee_pay_histories')
        ->where('employee_id', $employee)
        ->where('effective_date', '<=', $date)
        ->orderByDesc('effective_date')
        ->first();

    // Costing a past week must use the rate that applied then, not today's.
    expect((float) $rateOn('2026-03-15')->base_pay)->toBe(15.00)
        ->and((float) $rateOn('2026-08-10')->base_pay)->toBe(17.50)
        ->and((float) $rateOn('2026-08-10')->performance_pay)->toBe(1.50);
});

it('estimates the labour cost of a planned shift', function () {
    makeStore();
    $employee = makeEmployee('Costed');

    DB::table('employee_pay_histories')->insert([
        'employee_id' => $employee, 'base_pay' => 17.50, 'performance_pay' => 1.50, 'effective_date' => '2026-07-01',
    ]);

    // 13:00 to 21:00 with a 30 minute unpaid break = 7.5 paid hours.
    $shift = makeShift(['employee_id' => $employee, 'unpaid_break_minutes' => 30]);
    $row = DB::table('shifts')->find($shift);

    $paidHours = ((strtotime($row->end_at) - strtotime($row->start_at)) / 3600) - ($row->unpaid_break_minutes / 60);
    $rate = DB::table('employee_pay_histories')
        ->where('employee_id', $employee)
        ->where('effective_date', '<=', $row->business_date)
        ->orderByDesc('effective_date')
        ->first();

    expect($paidHours)->toBe(7.5)
        ->and(round($paidHours * ((float) $rate->base_pay + (float) $rate->performance_pay), 2))->toBe(142.50);
});

it('surfaces approved time off when a shift is placed on those dates', function () {
    makeStore();
    $off = makeEmployee('Away');
    $free = makeEmployee('Around');

    makeRequest($off, ['status' => 'approved']);
    makeRequest($free, ['status' => 'denied']);

    $conflicted = DB::table('employee_requests')
        ->where('request_type', 'time_off')
        ->where('status', 'approved')
        ->where('start_date', '<=', '2026-08-11')
        ->where('end_date', '>=', '2026-08-11')
        ->pluck('employee_id');

    // A denied request must not block anyone, and an approved one has to be
    // findable or the table is write-only.
    expect($conflicted->all())->toBe([$off]);
});

it('keeps every decision on a request, not just the last one', function () {
    $employee = makeEmployee();
    $request = makeRequest($employee);

    DB::table('employee_request_decisions')->insert([
        'employee_request_id' => $request,
        'decision' => 'approved',
        'notes' => 'cover arranged',
        'completed_at' => '2026-08-01 09:00:00',
    ]);
    DB::table('employee_request_decisions')->insert([
        'employee_request_id' => $request,
        'decision' => 'cancelled',
        'notes' => 'cover fell through',
        'completed_at' => '2026-08-03 14:00:00',
    ]);
    DB::table('employee_requests')->where('id', $request)->update(['status' => 'cancelled']);

    $trail = DB::table('employee_request_decisions')
        ->where('employee_request_id', $request)
        ->orderBy('completed_at')
        ->pluck('decision');

    // The reversal is visible. A status column alone would have erased the
    // approval that happened first.
    expect($trail->all())->toBe(['approved', 'cancelled'])
        ->and(DB::table('employee_requests')->find($request)->status)->toBe('cancelled');
});

it('keeps the decision trail when the deciding user is removed', function () {
    $employee = makeEmployee();
    $request = makeRequest($employee);
    $user = DB::table('users')->insertGetId([
        'name' => 'Manager', 'email' => 'm@example.test', 'password' => 'x',
    ]);

    DB::table('employee_request_decisions')->insert([
        'employee_request_id' => $request,
        'user_id' => $user,
        'decision' => 'approved',
    ]);

    DB::table('users')->where('id', $user)->delete();

    $row = DB::table('employee_request_decisions')->where('employee_request_id', $request)->first();
    expect($row)->not->toBeNull()
        ->and($row->decision)->toBe('approved')
        ->and($row->user_id)->toBeNull();
});

it('keeps a request when the shift it referred to is deleted', function () {
    makeStore();
    $employee = makeEmployee();
    $shift = makeShift(['employee_id' => $employee]);
    $request = makeRequest($employee, ['request_type' => 'cover_request', 'shift_id' => $shift]);

    DB::table('shifts')->where('id', $shift)->delete();

    expect(DB::table('employee_requests')->find($request)->shift_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Availability windows
|--------------------------------------------------------------------------
*/

function makeWindow(int $employee, string $day, string $from, string $to, ?string $type = null): int
{
    return DB::table('employee_availability_windows')->insertGetId([
        'employee_id' => $employee,
        'day_of_week' => $day,
        'available_from' => $from,
        'available_to' => $to,
        'shift_type' => $type,
    ]);
}

it('holds a concrete hour range for a weekday', function () {
    $employee = makeEmployee();
    makeWindow($employee, 'monday', '16:00:00', '21:00:00', 'PM');

    $w = DB::table('employee_availability_windows')->where('employee_id', $employee)->first();

    expect($w->day_of_week)->toBe('monday')
        ->and(substr($w->available_from, 0, 5))->toBe('16:00')
        ->and(substr($w->available_to, 0, 5))->toBe('21:00');
});

it('holds two separate windows on one day', function () {
    $employee = makeEmployee();
    makeWindow($employee, 'friday', '11:00:00', '14:00:00', 'AM');
    makeWindow($employee, 'friday', '17:00:00', '21:00:00', 'PM');

    // Two windows on one day is what a split shift is validated against.
    expect(DB::table('employee_availability_windows')->where('day_of_week', 'friday')->count())->toBe(2);
});

it('answers whether a shift falls inside an availability window', function () {
    $employee = makeEmployee();
    makeWindow($employee, 'monday', '16:00:00', '21:00:00');

    $fits = fn (string $from, string $to) => DB::table('employee_availability_windows')
        ->where('employee_id', $employee)
        ->where('day_of_week', 'monday')
        ->whereRaw('available_to > available_from')          // same-day window
        ->where('available_from', '<=', $from)
        ->where('available_to', '>=', $to)
        ->exists();

    expect($fits('17:00:00', '20:00:00'))->toBeTrue()   // inside
        ->and($fits('16:00:00', '21:00:00'))->toBeTrue()   // exactly the window
        ->and($fits('15:00:00', '20:00:00'))->toBeFalse()  // starts too early
        ->and($fits('17:00:00', '22:00:00'))->toBeFalse(); // runs too late
});

it('encodes an overnight window by column order, with no separate flag', function () {
    $employee = makeEmployee();
    makeWindow($employee, 'saturday', '20:00:00', '02:00:00', 'OP');
    makeWindow($employee, 'monday', '16:00:00', '21:00:00', 'PM');

    $wrapping = DB::table('employee_availability_windows')
        ->whereColumn('available_to', '<', 'available_from')
        ->pluck('day_of_week');

    // 20:00 -> 02:00 wraps; day_of_week names the evening it started on, not
    // the morning it ended. A boolean column here could contradict the hours;
    // the ordering cannot.
    expect($wrapping->all())->toBe(['saturday']);
});

it('refuses a duplicate window so a replay stays idempotent', function () {
    $employee = makeEmployee();
    makeWindow($employee, 'monday', '16:00:00', '21:00:00');

    expect(fn () => makeWindow($employee, 'monday', '16:00:00', '21:00:00'))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Split shifts
|--------------------------------------------------------------------------
*/

it('models a split shift as two grouped rows, not one row with a gap', function () {
    makeStore();
    $employee = makeEmployee('Split');
    $group = (string) Str::ulid();

    $lunch = makeShift([
        'employee_id' => $employee, 'split_group_id' => $group, 'split_part' => 1,
        'start_at' => '2026-08-10 15:00:00', 'end_at' => '2026-08-10 18:00:00',
    ]);
    $dinner = makeShift([
        'employee_id' => $employee, 'split_group_id' => $group, 'split_part' => 2,
        'start_at' => '2026-08-10 21:00:00', 'end_at' => '2026-08-11 01:00:00',
    ]);

    $parts = DB::table('shifts')->where('split_group_id', $group)->orderBy('split_part')->get();

    expect($parts)->toHaveCount(2)
        ->and($parts[0]->id)->toBe($lunch)
        ->and($parts[1]->id)->toBe($dinner)
        // Both parts belong to the day the assignment started.
        ->and($parts->pluck('business_date')->unique()->all())->toBe(['2026-08-10']);
});

it('does not count the gap between split parts as paid time', function () {
    makeStore();
    $employee = makeEmployee('Split');
    $group = (string) Str::ulid();

    makeShift(['employee_id' => $employee, 'split_group_id' => $group, 'split_part' => 1,
        'start_at' => '2026-08-10 15:00:00', 'end_at' => '2026-08-10 18:00:00']);
    makeShift(['employee_id' => $employee, 'split_group_id' => $group, 'split_part' => 2,
        'start_at' => '2026-08-10 21:00:00', 'end_at' => '2026-08-11 01:00:00']);

    $paid = (float) DB::table('shifts')->where('split_group_id', $group)->get()
        ->sum(fn ($s) => (strtotime($s->end_at) - strtotime($s->start_at)) / 3600
            - $s->unpaid_break_minutes / 60);

    // 3 hours plus 4 hours. The 3-hour gap is not paid, and it is not a break
    // either — storing it as unpaid_break_minutes would have made this 10.
    expect($paid)->toBe(7.0);
});

it('publishes each split part as its own shift', function () {
    makeStore();
    $employee = makeEmployee('Split');
    $group = (string) Str::ulid();

    makeShift(['employee_id' => $employee, 'split_group_id' => $group, 'split_part' => 1,
        'humanity_shift_id' => 'HS-3001', 'publish_state' => 'published']);
    makeShift(['employee_id' => $employee, 'split_group_id' => $group, 'split_part' => 2,
        'start_at' => '2026-08-10 21:00:00', 'end_at' => '2026-08-11 01:00:00',
        'humanity_shift_id' => 'HS-3002', 'publish_state' => 'published']);

    // Each part maps 1:1 onto a Humanity shift, so the publisher needs no
    // special case for splits.
    expect(DB::table('shifts')->where('split_group_id', $group)->pluck('humanity_shift_id')->all())
        ->toBe(['HS-3001', 'HS-3002']);
});

it('reconciles each split part against its own punches', function () {
    makeStore();
    $employee = makeEmployee('Split');
    $group = (string) Str::ulid();

    $part1 = makeShift(['employee_id' => $employee, 'split_group_id' => $group, 'split_part' => 1,
        'start_at' => '2026-08-10 15:00:00', 'end_at' => '2026-08-10 18:00:00']);
    $part2 = makeShift(['employee_id' => $employee, 'split_group_id' => $group, 'split_part' => 2,
        'start_at' => '2026-08-10 21:00:00', 'end_at' => '2026-08-11 01:00:00']);

    makeSegment(['employee_id' => $employee, 'shift_id' => $part1, 'match_source' => 'auto',
        'time_in' => '2026-08-10 14:58:00', 'time_out' => '2026-08-10 18:03:00']);
    makeSegment(['employee_id' => $employee, 'shift_id' => $part2, 'match_source' => 'auto',
        'time_in' => '2026-08-10 20:57:00', 'time_out' => '2026-08-11 01:06:00']);

    expect(DB::table('work_segments')->where('shift_id', $part1)->count())->toBe(1)
        ->and(DB::table('work_segments')->where('shift_id', $part2)->count())->toBe(1);
});

it('tells a split shift apart from a lunch break by how the punches map', function () {
    makeStore();
    $split = makeEmployee('Split');
    $breaker = makeEmployee('Breaker');

    // A split: two punches against two shift rows.
    $group = (string) Str::ulid();
    $p1 = makeShift(['employee_id' => $split, 'split_group_id' => $group, 'split_part' => 1]);
    $p2 = makeShift(['employee_id' => $split, 'split_group_id' => $group, 'split_part' => 2,
        'start_at' => '2026-08-10 21:00:00', 'end_at' => '2026-08-11 01:00:00']);
    makeSegment(['employee_id' => $split, 'shift_id' => $p1]);
    makeSegment(['employee_id' => $split, 'shift_id' => $p2, 'time_in' => '2026-08-10 21:00:00', 'time_out' => '2026-08-11 01:00:00']);

    // A break: two punches against ONE shift row.
    $one = makeShift(['employee_id' => $breaker]);
    makeSegment(['employee_id' => $breaker, 'shift_id' => $one, 'time_in' => '2026-08-10 13:00:00', 'time_out' => '2026-08-10 17:00:00']);
    makeSegment(['employee_id' => $breaker, 'shift_id' => $one, 'time_in' => '2026-08-10 17:30:00', 'time_out' => '2026-08-10 21:00:00']);

    $shiftsPerEmployee = fn (int $e) => DB::table('shifts')->where('employee_id', $e)->count();
    $segmentsPerEmployee = fn (int $e) => DB::table('work_segments')->where('employee_id', $e)->count();

    // Same punch count, different shift count. That distinction is the whole
    // reason work_segments.shift_id is a plain nullable FK and not a 1:1 link.
    expect($segmentsPerEmployee($split))->toBe(2)
        ->and($segmentsPerEmployee($breaker))->toBe(2)
        ->and($shiftsPerEmployee($split))->toBe(2)
        ->and($shiftsPerEmployee($breaker))->toBe(1);
});

it('totals a split day across both parts', function () {
    makeStore();
    $employee = makeEmployee('Split');

    makeSegment(['employee_id' => $employee, 'time_in' => '2026-08-10 15:00:00', 'time_out' => '2026-08-10 18:00:00', 'hours' => 3.00]);
    makeSegment(['employee_id' => $employee, 'time_in' => '2026-08-10 21:00:00', 'time_out' => '2026-08-11 01:00:00', 'hours' => 4.00]);

    // Daily-hours and overtime rules are asked of the day, not the shift, and
    // business_date keeps the after-midnight half on the day it started.
    $total = DB::table('work_segments')
        ->where('employee_id', $employee)
        ->where('business_date', '2026-08-10')
        ->sum('hours');

    expect((float) $total)->toBe(7.0);
});

it('validates each split part against its own availability window', function () {
    makeStore();
    $employee = makeEmployee('Split');
    makeWindow($employee, 'monday', '11:00:00', '14:00:00');
    makeWindow($employee, 'monday', '17:00:00', '21:00:00');

    $fits = fn (string $from, string $to) => DB::table('employee_availability_windows')
        ->where('employee_id', $employee)
        ->where('day_of_week', 'monday')
        ->where('available_from', '<=', $from)
        ->where('available_to', '>=', $to)
        ->exists();

    // Each part is checked against the windows independently; the gap between
    // them does not need to be covered, because nobody is working then.
    expect($fits('11:00:00', '14:00:00'))->toBeTrue()
        ->and($fits('17:00:00', '21:00:00'))->toBeTrue()
        // A single block spanning the whole day fits neither window.
        ->and($fits('11:00:00', '21:00:00'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The replay guarantee
|--------------------------------------------------------------------------
*/

it('leaves scheduling-owned rows untouched when projections are rebuilt', function () {
    makeStore();
    $employee = makeEmployee();
    $shift = makeShift(['employee_id' => $employee, 'humanity_shift_id' => 'HS-2001']);

    DB::table('store_settings')->insert(['store_id' => 1, 'timezone' => 'America/Chicago']);
    DB::table('integration_identities')->insert([
        'entity_type' => 'employee',
        'entity_id' => $employee,
        'system' => 'humanity',
        'external_id' => 'HE-77',
        'sync_state' => 'synced',
    ]);

    // A projection rebuild wipes and re-derives the read models. Neither
    // store_settings nor integration_identities carries an FK into them, so
    // neither is dragged along.
    DB::table('employee_availability_windows')->delete();
    DB::table('employee_pay_histories')->delete();

    expect(DB::table('store_settings')->where('store_id', 1)->value('timezone'))->toBe('America/Chicago')
        ->and(DB::table('integration_identities')->where('entity_id', $employee)->value('external_id'))->toBe('HE-77')
        ->and(DB::table('shifts')->find($shift)->humanity_shift_id)->toBe('HS-2001');
});
