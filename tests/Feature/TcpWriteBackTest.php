<?php

use App\Enums\TcpSyncState;
use App\Jobs\PushEmployeeToTcp;
use App\Jobs\PushWorkSegmentToTcp;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Models\Shift;
use App\Models\WorkSegment;
use App\Services\EventConsume\Handlers\EmployeeCreatedHandler;
use App\Services\Scheduling\TcpEmployeeWriter;
use App\Services\Scheduling\TcpWorkSegmentWriter;
use App\Services\Scheduling\WorkSegmentService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What reaches TCP, and what must not reach Humanity
|--------------------------------------------------------------------------
|
| Two separate guarantees the workflow document demands:
|
|   TCP is the timeclock. Every edit to a worked segment must reach it, or
|   payroll pays from a number the clock does not agree with.
|
|   Humanity is the published schedule and a POST there is LIVE IMMEDIATELY.
|   Nothing may reach it until somebody hits publish.
|
| Queue::fake() is the right instrument for the first: the pushes are queued on
| purpose, so asserting the dispatch is asserting the contract. Http::fake()
| with a strict assertNothingSent() is the instrument for the second.
|
*/

beforeEach(function () {
    // Static-token auth: with the default 'oauth' mode the client tries to
    // exchange credentials first and dies on "client_id is not set" before it
    // ever reaches the endpoint under test.
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'test-token');
    config()->set('humanity.auth_mode', 'static');
    config()->set('humanity.static_token', 'test-token');

    // phpunit.xml runs the queue synchronously, so the pushes the seeder
    // dispatches would execute inline and skew every counter in this file.
    // Faking the queue first keeps the seed inert; each test re-fakes to reset
    // the recorded set to just its own dispatches.
    Queue::fake();

    // Deliberately NO bare Http::fake() here. Stubs are matched in
    // registration order and the first match wins, so a catch-all registered
    // now would shadow every per-test Http::fake([...]) — which is how a 422
    // test quietly passed a 200 and asserted the wrong thing. With the queue
    // faked the seeder issues no requests anyway; preventStrayRequests turns
    // any test that forgets to stub into a loud failure instead of a real call
    // to the vendor.
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
    $this->today = app(BusinessDay::class)->toLocal(DemoSeeder::STORE_ID, now())->toDateString();

    // Every route requires a token the auth service issued.
    signIn();
});

// ── work segments → TCP ─────────────────────────────────────────────────

it('queues a TCP push when hours are approved', function () {
    Queue::fake();
    $segment = WorkSegment::whereNotNull('time_out')->where('manager_approval', false)->firstOrFail();

    app(WorkSegmentService::class)->approve($segment, 1);

    Queue::assertPushed(PushWorkSegmentToTcp::class,
        fn (PushWorkSegmentToTcp $job): bool => $job->workSegmentId === $segment->id);

    expect($segment->fresh()->tcp_sync_state)->toBe(TcpSyncState::Pending);
});

it('queues a TCP push when times are corrected', function () {
    Queue::fake();
    $segment = WorkSegment::whereNotNull('time_out')->firstOrFail();

    app(WorkSegmentService::class)->correctTimes(
        $segment,
        $segment->time_in->copy()->subMinutes(10),
        $segment->time_out->copy()->addMinutes(10),
    );

    Queue::assertPushed(PushWorkSegmentToTcp::class);
    expect($segment->fresh()->tcp_sync_state)->toBe(TcpSyncState::Pending);
});

it('queues a TCP push when a segment is created for someone who forgot to clock in', function () {
    Queue::fake();
    $employee = Employee::firstOrFail();

    app(WorkSegmentService::class)->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employee->id,
        'time_in_local' => "{$this->today} 09:00:00",
        'time_out_local' => "{$this->today} 12:00:00",
    ]);

    Queue::assertPushed(PushWorkSegmentToTcp::class);
});

it('queues a TCP delete when a segment is removed', function () {
    Queue::fake();
    $segment = WorkSegment::firstOrFail();

    app(WorkSegmentService::class)->delete($segment);

    Queue::assertPushed(PushWorkSegmentToTcp::class,
        fn (PushWorkSegmentToTcp $job): bool => $job->workSegmentId === $segment->id);
});

it('pushes every individually approved segment to TCP', function () {
    Queue::fake();
    $segments = WorkSegment::whereNotNull('time_out')->where('manager_approval', false)->get();

    // There is no bulk approval: each employee's hours are approved on their
    // own, and each one is its own PUT, so a rejection strands nobody else.
    foreach ($segments as $segment) {
        app(WorkSegmentService::class)->approve($segment, 1);
    }

    Queue::assertPushed(PushWorkSegmentToTcp::class, $segments->count());
});

// ── employees → TCP ─────────────────────────────────────────────────────

it('queues a TCP push when a hiring employee event is projected', function () {
    Queue::fake();

    app(EmployeeCreatedHandler::class)->handle([
        'id' => '01JEMPLOYEE',
        'subject' => 'hiring.v1.employee.created',
        'data' => ['employee' => [
            'id' => 9001,
            'first_name' => 'Nadia',
            'last_name' => 'Haddad',
            'employment_type' => 'W2',
            'updated_at' => now()->toIso8601String(),
            'status_histories' => [['status' => 'hired', 'effective_date' => now()->toDateString()]],
        ]],
    ]);

    expect(Employee::find(9001))->not->toBeNull();
    Queue::assertPushed(PushEmployeeToTcp::class,
        fn (PushEmployeeToTcp $job): bool => $job->employeeId === 9001);
});

it('sends a termination to TCP as an update, never a delete', function () {
    $employee = Employee::firstOrFail();
    $employee->forceFill([
        'current_status' => 'terminated',
        'current_status_effective_date' => $this->today,
    ])->save();

    // Give it an existing TCP id so this is an update path, not a create.
    IntegrationIdentity::create([
        'entity_type' => 'employee',
        'entity_id' => $employee->id,
        'system' => 'tcp',
        'external_id' => 'TCP-1',
        'sync_state' => 'synced',
    ]);

    Http::fake(['*' => Http::response(['id' => 'TCP-1'], 200)]);

    app(TcpEmployeeWriter::class)->sync($employee->fresh());

    // A PUT that marks them inactive. Approved hours and closed shifts still
    // have to resolve to a person for payroll after they leave.
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/employees/TCP-1')
        && $request->data()['isActive'] === false);

    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
});

it('records the TCP employee id in the owned table, not on the projection', function () {
    $employee = Employee::firstOrFail();

    Http::fake(['*' => Http::response(['employeeId' => 'E-77', 'employeeRecordId' => 'R-88'], 200)]);

    app(TcpEmployeeWriter::class)->sync($employee);

    // integration_identities survives a projection rebuild; the employees row
    // does not. Putting the id we obtained onto the projection would mean the
    // next replay lost it and we created the person in TCP a second time.
    $identity = IntegrationIdentity::where('entity_id', $employee->id)->firstOrFail();

    expect($identity->external_id)->toBe('E-77')
        ->and($identity->external_record_id)->toBe('R-88')
        ->and(TcpEmployeeWriter::resolve($employee->fresh())['external_id'])->toBe('E-77');
});

// ── the writer itself ───────────────────────────────────────────────────

it('PUTs an existing segment and marks it synced', function () {
    Http::fake(['*' => Http::response(['id' => 'WS-1'], 200)]);
    $segment = WorkSegment::whereNotNull('time_out')->firstOrFail();
    $segment->forceFill(['tcp_segment_id' => 'WS-1'])->save();

    app(TcpWorkSegmentWriter::class)->push($segment->fresh()->load('employee'));

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/worksegments/WS-1'));

    expect($segment->fresh()->tcp_sync_state)->toBe(TcpSyncState::Synced);
});

it('marks a segment failed and does not retry when TCP rejects the payload', function () {
    Http::fake(['*' => Http::response(['message' => 'bad request'], 422)]);
    $segment = WorkSegment::whereNotNull('time_out')->firstOrFail();
    $segment->forceFill(['tcp_segment_id' => 'WS-2'])->save();

    // A 4xx is not rethrown: it would be rejected identically on every retry,
    // so it is recorded for a human instead of burning six queue attempts.
    app(TcpWorkSegmentWriter::class)->push($segment->fresh()->load('employee'));

    $fresh = $segment->fresh();
    expect($fresh->tcp_sync_state)->toBe(TcpSyncState::Failed)
        ->and($fresh->tcp_sync_attempts)->toBe(1)
        ->and($fresh->tcp_sync_error)->not->toBeNull();
});

it('sends timeOut even when it is null, so a mistaken clock-out can be reopened', function () {
    Http::fake(['*' => Http::response(['id' => 'WS-3'], 200)]);
    $open = WorkSegment::whereNull('time_out')->firstOrFail();
    $open->forceFill(['tcp_segment_id' => 'WS-3'])->save();

    app(TcpWorkSegmentWriter::class)->push($open->fresh()->load('employee'));

    // array_filter would have stripped the null and the reopen would silently
    // not happen, so timeOut is added after the filter on purpose.
    Http::assertSent(fn ($request) => array_key_exists('timeOut', $request->data())
        && $request->data()['timeOut'] === null);
});

// ── Humanity stays untouched until publish ──────────────────────────────

it('sends nothing to Humanity when a shift is created, edited or deleted', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $employee = Employee::firstOrFail();

    $this->post('/board/shifts', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
        'employee_id' => $employee->id,
        'start' => '09:00',
        'end' => '12:00',
    ])->assertRedirect();

    $shift = Shift::latest('id')->firstOrFail();

    $this->put("/board/shifts/{$shift->id}", [
        'date' => $this->today,
        'employee_id' => $employee->id,
        'start' => '10:00',
        'end' => '13:00',
    ])->assertRedirect();

    $this->post("/board/shifts/{$shift->id}", ['_method' => 'DELETE'])->assertRedirect();

    // "The whole scheduling will be handled on our platform until the user hit
    // publish." A POST to Humanity is live the instant it lands.
    Http::assertNothingSent();
});

it('refuses to edit a published shift, then re-sends it as a PUT once unpublished', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $shift = Shift::whereNotNull('employee_id')->firstOrFail();
    $shift->forceFill([
        'publish_state' => 'published',
        'humanity_shift_id' => 'HS-9',
        'payload_fingerprint' => str_repeat('a', 64),
    ])->save();

    // Locked. The edit bounces rather than silently diverging from what
    // employees can already see in Humanity.
    $this->put("/board/shifts/{$shift->id}", [
        'date' => $this->today,
        'employee_id' => $shift->employee_id,
        'start' => '15:00',
        'end' => '19:00',
    ])->assertRedirect();

    expect(session('err'))->toContain('published')
        ->and($shift->fresh()->payload_fingerprint)->not->toBeNull();

    // Unpublishing keeps the shift in Humanity AND keeps its id — that is what
    // makes the next publish a PUT instead of a duplicate POST.
    $this->post("/board/shifts/{$shift->id}/unpublish")->assertRedirect();

    expect($shift->fresh()->publish_state->value)->toBe('unlocked')
        ->and($shift->fresh()->humanity_shift_id)->toBe('HS-9');

    $this->put("/board/shifts/{$shift->id}", [
        'date' => $this->today,
        'employee_id' => $shift->employee_id,
        'start' => '15:00',
        'end' => '19:00',
    ])->assertRedirect();

    $fresh = $shift->fresh();

    // Still linked to its Humanity shift — the publisher updates it rather
    // than creating a duplicate — but no longer skippable as unchanged.
    expect($fresh->payload_fingerprint)->toBeNull()
        ->and($fresh->humanity_shift_id)->toBe('HS-9');

    Http::assertNothingSent();
});
