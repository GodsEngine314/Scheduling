<?php

use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Position;
use App\Models\Shift;
use App\Models\TcpEmployeeJobCode;
use App\Models\TcpJobCode;
use App\Models\TcpJobCodeRole;
use App\Models\WorkSegment;
use App\Services\Scheduling\TcpEmployeeJobCodeReader;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The job code belongs to the person, not to a dropdown
|--------------------------------------------------------------------------
|
| Every punch needs a jobCodeId. It used to be ASSEMBLED — a manager picked a
| position and franchise+store+role was built from it, on the hope TCP had that
| combination. It frequently did not: three of our positions map to no TCP code
| anywhere, one exists at a single store, and some store numbers cannot form a
| code at all. The failure was the bad kind — the punch saved, showed on the
| board, and was refused by the vendor afterwards.
|
| TCP has been assigning codes to people the whole time. GET /employeejobcodes
| returns them, and its own timeclock files hours against exactly those
| assignments. Live, across two real stores, twenty of twenty employees carried
| exactly ONE per-store role code and none carried two.
|
| So the question stopped being "what role was this" and became "what is this
| person's code", which has an answer we can look up. What these tests pin is
| that the lookup is store-correct, that pay categories are never mistaken for
| roles, and that removing the field did not remove the value.
|
*/

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    // The estate mapping, as PositionSeeder builds it from the live catalogue.
    // Suffix 01 is Crew Member and 02 is Crew Leader.
    // firstOrCreate: DemoSeeder's positions are Insider, Driver and Shift Lead —
    // its own inventions, none of which TCP has a code for anywhere. The estate's
    // real roles have to be made here.
    $crew = (int) Position::query()->firstOrCreate(['label' => 'Crew Member'])->id;
    $lead = (int) Position::query()->firstOrCreate(['label' => 'Crew Leader'])->id;

    TcpJobCodeRole::query()->insert([
        ['role_suffix' => '01', 'tcp_label' => 'Crew Member', 'position_id' => $crew, 'code_count' => 38, 'created_at' => now(), 'updated_at' => now()],
        ['role_suffix' => '02', 'tcp_label' => 'Crew Leader', 'position_id' => $lead, 'code_count' => 38, 'created_at' => now(), 'updated_at' => now()],
    ]);

    TcpJobCode::query()->insert([
        ['job_code_id' => '37951001', 'store_key' => '379510', 'role_suffix' => '01', 'description' => 'Crew Member - 3795-10', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['job_code_id' => '37951002', 'store_key' => '379510', 'role_suffix' => '02', 'description' => 'Crew Leader - 3795-10', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['job_code_id' => '37950401', 'store_key' => '379504', 'role_suffix' => '01', 'description' => 'Crew Member - 3795-04', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->crew = $crew;
    $this->lead = $lead;

    signIn();
});

/**
 * One employee at a real roster store, carrying the TCP ids a real one has.
 *
 * Named at length because Pest helper functions are GLOBAL across the suite —
 * a plain tcpEmployee() collides with EmployeeSeederTest's, which builds a
 * vendor payload rather than a row.
 */
function rosterEmployeeWithTcpIds(string $tcpId = '6573538'): Employee
{
    return tap(Employee::query()->firstOrFail(), function (Employee $e) use ($tcpId): void {
        $e->forceFill([
            'primary_store_id' => 379500010,
            'tcp_employee_id' => $tcpId,
            'tcp_employee_record_id' => '10092566',
        ])->saveQuietly();
    });
}

/**
 * The endpoint's real envelope, as the live probe returned it: a role code and a
 * company-wide pay category side by side, told apart only by their shape.
 */
function fakeJobCodeAssignments(array $rows): void
{
    Http::fake(['*employeejobcodes*' => Http::response(['data' => $rows], 200)]);
}

function jobCodeAssignment(string $tcpEmployeeId, string $jobCodeId, string $description, string $recordId = '10125461'): array
{
    return [
        'id' => $recordId,
        'employeeId' => (int) $tcpEmployeeId,
        'jobCodeId' => $jobCodeId,
        'description' => $description,
    ];
}

// ── reading the assignments ─────────────────────────────────────────────

it('stores the role code and the pay category, and tells them apart', function () {
    $employee = rosterEmployeeWithTcpIds();

    fakeJobCodeAssignments([
        jobCodeAssignment('6573538', '37951001', 'Crew Member - 3795-10'),
        jobCodeAssignment('6573538', '1003', 'Bonus', '10125334'),
    ]);

    $report = app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    expect($report['written'])->toBe(2)
        ->and($report['roles'])->toBe(1);

    $role = TcpEmployeeJobCode::query()->where('job_code_id', '37951001')->firstOrFail();
    $pay = TcpEmployeeJobCode::query()->where('job_code_id', '1003')->firstOrFail();

    // A ROLE carries a store and a suffix, because eight digits mean something.
    expect($role->is_role)->toBeTrue()
        ->and($role->store_key)->toBe('379510')
        ->and($role->role_suffix)->toBe('01')
        ->and($role->employee_id)->toBe($employee->id);

    // A PAY CATEGORY carries neither. Reading digits out of 1003 would give a
    // store_key of '10' and a suffix of '03', both meaningless and both indexed.
    expect($pay->is_role)->toBeFalse()
        ->and($pay->store_key)->toBeNull()
        ->and($pay->role_suffix)->toBeNull();
});

it('never files hours under a pay category', function () {
    $employee = rosterEmployeeWithTcpIds();

    // ONLY a pay category. "Bonus" describes how an hour is paid, not what
    // anybody did, so this person has no role and no code to send.
    fakeJobCodeAssignments([jobCodeAssignment('6573538', '1003', 'Bonus')]);

    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    expect(TcpEmployeeJobCode::jobCodeIdFor($employee->id, '03795-00010'))->toBeNull();
});

it('never creates an employee TCP knows and hiring has not sent', function () {
    rosterEmployeeWithTcpIds();

    fakeJobCodeAssignments([
        jobCodeAssignment('6573538', '37951001', 'Crew Member - 3795-10'),
        // Nobody. People arrive from hiring over NATS; inventing one here would
        // put a row in a projection the next replay erases.
        jobCodeAssignment('9999999', '37951001', 'Crew Member - 3795-10', '10125999'),
    ]);

    $before = Employee::query()->count();
    $report = app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    expect(Employee::query()->count())->toBe($before)
        ->and($report['unmatched'])->toHaveCount(1)
        ->and($report['unmatched'][0]['tcp_employee_id'])->toBe('9999999');
});

it('prunes an assignment TCP has stopped reporting', function () {
    $employee = rosterEmployeeWithTcpIds();

    /*
     * A SEQUENCE, not two Http::fake() calls. Stubs are matched in registration
     * order and the FIRST match wins, so a second fake() for the same pattern is
     * shadowed by the first and both syncs would see the same two codes — the
     * test would pass the assertion below only by never pruning anything.
     */
    Http::fakeSequence('*employeejobcodes*')
        ->push(['data' => [
            jobCodeAssignment('6573538', '37951001', 'Crew Member - 3795-10'),
            jobCodeAssignment('6573538', '37951002', 'Crew Leader - 3795-10', '10125462'),
        ]])
        ->push(['data' => [
            jobCodeAssignment('6573538', '37951001', 'Crew Member - 3795-10'),
        ]]);

    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);
    expect(TcpEmployeeJobCode::query()->where('employee_id', $employee->id)->count())->toBe(2);

    // TCP moved them off Crew Leader. A row left behind here is a code we would
    // go on sending after the vendor stopped accepting it.
    $report = app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    expect($report['pruned'])->toBe(1)
        ->and(TcpEmployeeJobCode::query()->where('job_code_id', '37951002')->exists())->toBeFalse()
        ->and(TcpEmployeeJobCode::query()->where('job_code_id', '37951001')->exists())->toBeTrue();
});

it('does not let one store prune another store assignment', function () {
    $employee = rosterEmployeeWithTcpIds();

    // A code at a DIFFERENT store, for somebody who covers both.
    TcpEmployeeJobCode::query()->create([
        'employee_id' => $employee->id,
        'tcp_employee_id' => '6573538',
        'job_code_id' => '37950401',
        'store_key' => '379504',
        'role_suffix' => '01',
        'is_role' => true,
    ]);

    fakeJobCodeAssignments([jobCodeAssignment('6573538', '37951001', 'Crew Member - 3795-10')]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    // Syncing store 10 must not delete store 4's mapping — it asked TCP nothing
    // about store 4 and so knows nothing about it.
    expect(TcpEmployeeJobCode::query()->where('job_code_id', '37950401')->exists())->toBeTrue();
});

it('asks TCP nothing for a store whose number cannot form a code', function () {
    // DemoSeeder's 4821 has no franchise prefix. Nothing could be matched to it
    // even if an assignment came back, so no request is worth making.
    $report = app(TcpEmployeeJobCodeReader::class)->syncStore(DemoSeeder::STORE_ID);

    Http::assertNothingSent();
    expect($report['skipped'][0]['reason'])->toBe('store_number_cannot_form_a_job_code');
});

// ── the lookup is store-correct ─────────────────────────────────────────

it('scopes a code to the store it names', function () {
    $employee = rosterEmployeeWithTcpIds();

    fakeJobCodeAssignments([jobCodeAssignment('6573538', '37951001', 'Crew Member - 3795-10')]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    // 37951001 is Crew Member AT STORE 10 and says nothing about store 42.
    // Filing a cover shift under the home store's code books the hours to the
    // wrong store's labour.
    expect(TcpEmployeeJobCode::jobCodeIdFor($employee->id, '03795-00010'))->toBe('37951001')
        ->and(TcpEmployeeJobCode::jobCodeIdFor($employee->id, '03795-00042'))->toBeNull()
        // A store TCP cannot name matches nothing, rather than falling back to
        // any code the person happens to hold.
        ->and(TcpEmployeeJobCode::jobCodeIdFor($employee->id, null))->toBeNull();
});

it('translates the code into our own position', function () {
    $employee = rosterEmployeeWithTcpIds();

    fakeJobCodeAssignments([jobCodeAssignment('6573538', '37951002', 'Crew Leader - 3795-10')]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    // Suffix 02, so Crew Leader. This is the value the removed dropdown used to
    // be asked for, and the board still stores it on every shift and punch.
    expect(TcpEmployeeJobCode::positionIdFor($employee->id, '03795-00010'))->toBe($this->lead);
});

// ── the forms ───────────────────────────────────────────────────────────

it('offers no position field on the hand-entry punch form', function () {
    $employee = rosterEmployeeWithTcpIds();

    fakeJobCodeAssignments([jobCodeAssignment('6573538', '37951001', 'Crew Member - 3795-10')]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $html = $this->get(route('board.week', [
        'store' => 379500010, 'week' => '2026-08-11', 'view' => 'actual',
    ]))->assertOk()->getContent();

    // The role is on the NAME now, not in a field of its own.
    expect($html)->toContain($employee->fullName())
        ->and($html)->toContain('Crew Member');

    // And the punch form carries no position input at all.
    $form = substr($html, (int) strpos($html, 'board.segments.store'), 2000);
    expect($form)->not->toContain('name="position_id"');
});

it('says on the option itself when somebody cannot have hours filed', function () {
    rosterEmployeeWithTcpIds();

    // Nobody synced, so nobody has a code.
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $html = $this->get(route('board.week', [
        'store' => 379500010, 'week' => '2026-08-11', 'view' => 'actual',
    ]))->assertOk()->getContent();

    // Before it is chosen, rather than as an error after saving.
    expect($html)->toContain('no TCP job code');
});

it('keeps a position field for an open slot, which has nobody to inherit from', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $html = $this->get(route('board.week', [
        'store' => 379500010, 'week' => '2026-08-11', 'view' => 'planned',
    ]))->assertOk()->getContent();

    /*
     * THE ONE PICKER LEFT, and it is not a leftover. An open shift has no person
     * to take a role from, and the role IS its whole content — "we need a Driver
     * on Friday". Humanity also refuses a shift carrying no position at all, so
     * removing this would make open shifts unpublishable.
     */
    expect($html)->toContain('Position (open slot)')
        ->and($html)->toContain('js-open-shift-only');
});

// ── it happens on its own ───────────────────────────────────────────────

it('pulls assignments when the board lands on a store, with nobody pressing anything', function () {
    rosterEmployeeWithTcpIds();

    Http::fake([
        '*employeejobcodes*' => Http::response([
            'data' => [jobCodeAssignment('6573538', '37951001', 'Crew Member - 3795-10')],
        ], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    expect(TcpEmployeeJobCode::query()->count())->toBe(0);

    $this->get(route('board', ['store' => 379500010]))->assertOk();

    // Same principle as the punch heartbeat: if TCP knows the answer, the
    // console does not ask a manager for it.
    expect(TcpEmployeeJobCode::query()->where('is_role', true)->count())->toBe(1);
});

it('derives the position on a hand-entered punch with no position posted', function () {
    $employee = rosterEmployeeWithTcpIds();

    fakeJobCodeAssignments([jobCodeAssignment('6573538', '37951002', 'Crew Leader - 3795-10')]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    Http::fake(['*' => Http::response(['data' => [['id' => 'WS-9']], 'errors' => []], 200)]);

    // NOTE WHAT IS ABSENT: position_id. The form no longer sends it.
    $this->post('/board/segments', [
        'store_id' => 379500010,
        'employee_id' => $employee->id,
        'date' => '2026-08-11',
        'time_in' => '17:00',
        'time_out' => '21:00',
    ])->assertSessionHas('ok');

    $segment = WorkSegment::query()
        ->where('store_id', 379500010)
        ->where('employee_id', $employee->id)
        ->latest('id')
        ->firstOrFail();

    expect($segment->position_id)->toBe($this->lead);
});

it('derives the position on a planned shift with no position posted', function () {
    $employee = rosterEmployeeWithTcpIds();

    fakeJobCodeAssignments([jobCodeAssignment('6573538', '37951002', 'Crew Leader - 3795-10')]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->post('/board/shifts', [
        'store_id' => 379500010,
        'date' => '2026-08-11',
        'employee_id' => $employee->id,
        'start' => '17:00',
        'end' => '21:00',
    ])->assertRedirect();

    $shift = Shift::query()
        ->where('store_id', 379500010)
        ->where('employee_id', $employee->id)
        ->latest('id')
        ->firstOrFail();

    expect($shift->position_id)->toBe($this->lead);
});

it('ignores a position the request carries for somebody with a TCP role', function () {
    $employee = rosterEmployeeWithTcpIds();

    fakeJobCodeAssignments([jobCodeAssignment('6573538', '37951002', 'Crew Leader - 3795-10')]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    // A stale page or a hand-rolled POST. The dropdown is gone, so anything
    // arriving in this field is not something anybody chose on purpose.
    $this->post('/board/shifts', [
        'store_id' => 379500010,
        'date' => '2026-08-11',
        'employee_id' => $employee->id,
        'position_id' => (int) Position::query()->where('label', 'Driver')->value('id'),
        'start' => '17:00',
        'end' => '21:00',
    ])->assertRedirect();

    $shift = Shift::query()
        ->where('store_id', 379500010)
        ->where('employee_id', $employee->id)
        ->latest('id')
        ->firstOrFail();

    expect($shift->position_id)->toBe($this->lead);
});

it('falls back to the hiring profile when TCP has no assignment', function () {
    /*
     * THE SECOND SOURCE, and the one that owns the fact. TCP assigns codes per
     * store and has none for most people until that store is synced; hiring
     * publishes what somebody is employed as on
     * hiring.v1.employee.created|updated, effective-dated. Either way the role
     * comes off the PERSON - see BoardController::plannedPositionId(), which
     * reads TCP first only because hiring's vocabulary is wider than either
     * vendor's and a role neither carries cannot be published.
     *
     * Before this, a planned shift for somebody TCP had not assigned fell through
     * to whatever position_id the request carried, which came from a select
     * hidden by CSS. They were rostered as whatever happened to be first in it.
     */
    $employee = rosterEmployeeWithTcpIds();
    $insider = Position::query()->firstOrCreate(['label' => 'Insider']);

    $employee->forceFill(['primary_position_id' => $insider->id])->saveQuietly();
    EmployeePosition::query()->where('employee_id', $employee->id)->delete();

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    // No TCP assignment anywhere, and no position_id in the request.
    $this->post('/board/shifts', [
        'store_id' => 379500010,
        'date' => '2026-08-11',
        'employee_id' => $employee->id,
        'start' => '17:00',
        'end' => '21:00',
    ])->assertRedirect();

    $shift = Shift::query()
        ->where('store_id', 379500010)
        ->where('employee_id', $employee->id)
        ->latest('id')
        ->firstOrFail();

    expect($shift->position_id)->toBe($insider->id);
});

it('prefers the TCP assignment over the hiring profile where both answer', function () {
    // The only case the two can disagree, and TCP wins for one narrow reason:
    // its code is by definition one TCP has, and its role maps to a Humanity
    // schedule. Hiring's Driver, Insider and Shift Lead map to neither vendor, so
    // preferring them would take somebody TCP has already placed as Crew Leader
    // and roster them as something that cannot publish.
    $employee = rosterEmployeeWithTcpIds();
    $insider = Position::query()->firstOrCreate(['label' => 'Insider']);

    $employee->forceFill(['primary_position_id' => $insider->id])->saveQuietly();
    EmployeePosition::query()->where('employee_id', $employee->id)->delete();

    fakeJobCodeAssignments([jobCodeAssignment('6573538', '37951002', 'Crew Leader - 3795-10')]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->post('/board/shifts', [
        'store_id' => 379500010,
        'date' => '2026-08-11',
        'employee_id' => $employee->id,
        'start' => '17:00',
        'end' => '21:00',
    ])->assertRedirect();

    $shift = Shift::query()
        ->where('store_id', 379500010)
        ->where('employee_id', $employee->id)
        ->latest('id')
        ->firstOrFail();

    expect($shift->position_id)->toBe($this->lead);
});

it('does not wipe a shift position when neither system has a role for them', function () {
    /*
     * THE REGRESSION THIS CHANGE NEARLY SHIPPED. Deriving the position means
     * writing it, and writing a null would CLEAR the role off the row - after
     * which the shift cannot be published at all, because Humanity requires a
     * schedule (its name for a position) on every one. It would have surfaced
     * days later as a publish failure with nothing pointing at the edit.
     *
     * So a person neither TCP nor hiring has a role for leaves the shift's own
     * role alone.
     */
    $employee = rosterEmployeeWithTcpIds();
    $driver = Position::query()->firstOrCreate(['label' => 'Driver']);

    // Hiring knows nothing about them either: no history, no primary.
    $employee->forceFill(['primary_position_id' => null])->saveQuietly();
    EmployeePosition::query()->where('employee_id', $employee->id)->delete();

    $shift = Shift::query()->create([
        'store_id' => 379500010,
        'employee_id' => $employee->id,
        'position_id' => $driver->id,
        'business_date' => '2026-08-11',
        'start_at' => '2026-08-11 22:00:00',
        'end_at' => '2026-08-12 02:00:00',
    ]);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->put('/board/shifts/'.$shift->id, [
        'date' => '2026-08-11',
        'employee_id' => $employee->id,
        'start' => '18:00',
        'end' => '22:00',
    ])->assertRedirect();

    expect($shift->fresh()->position_id)->toBe($driver->id);
});

it('moves the role with the person when a shift is dragged onto another row', function () {
    /*
     * A DROP IS A REASSIGNMENT. move() and copy() carry the position over
     * untouched, which was right while somebody picked it and wrong now that it
     * belongs to whoever is on the shift: dragging a Crew Leader's shift onto
     * somebody else left it costed and published under the previous person's
     * role, with nothing on screen saying so.
     */
    $lead = rosterEmployeeWithTcpIds();
    $crew = rosterEmployeeWithTcpIds('6573539');

    // Two people, two different TCP roles at this store.
    fakeJobCodeAssignments([
        jobCodeAssignment('6573538', '37951002', 'Crew Leader - 3795-10'),
        jobCodeAssignment('6573539', '37951001', 'Crew Member - 3795-10'),
    ]);
    app(TcpEmployeeJobCodeReader::class)->syncStore(379500010);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $shift = Shift::query()->create([
        'store_id' => 379500010,
        'employee_id' => $lead->id,
        'position_id' => $this->lead,
        'business_date' => '2026-08-11',
        'start_at' => '2026-08-11 22:00:00',
        'end_at' => '2026-08-12 02:00:00',
    ]);

    $this->postJson('/board/shifts/'.$shift->id.'/move', [
        'business_date' => '2026-08-12',
        'employee_id' => $crew->id,
    ])->assertOk();

    expect($shift->fresh()->position_id)->toBe($this->crew);
});
