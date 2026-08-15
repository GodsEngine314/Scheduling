<?php

use App\Models\Employee;
use App\Models\WorkSegment;
use App\Services\Scheduling\WorkSegmentSyncService;
use App\Support\BusinessDay;
use App\Support\Integrations\Tcp\TcpClient;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The inbound field mapping, against TCP's real response schema
|--------------------------------------------------------------------------
|
| Every key asserted here comes from the documented GET /worksegments payload,
| not from a guess. Three of them are traps:
|
|   laborCodes and shiftNotes are LISTS mapping onto single columns.
|   breakLength is a STRING, not a count of minutes.
|   There is NO hours field — it has to be derived, which is the opposite of
|   what this service's comments used to claim.
|
*/

const MAP_STORE_ID = 379500001;

const MAP_STORE_NUMBER = '03795-00001';

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->employee = Employee::query()->firstOrFail();
    $this->employee->update(['tcp_employee_id' => 'E-1', 'primary_store_id' => MAP_STORE_ID]);

    $this->sync = app(WorkSegmentSyncService::class);
    $this->bd = app(BusinessDay::class);
});

/**
 * One record in the shape TCP ACTUALLY sends.
 *
 * Copied from a live response, not from the documented schema. The difference
 * matters: `breakLength`, `costCode` and `employeeDefaultLocationId` appear in
 * the docs and in NONE of 200 real records, so a fixture carrying them tests a
 * payload that does not exist. Tests that need them pass them explicitly.
 */
function tcpSegment(array $overrides = []): void
{
    // THE PAYLOAD IS SWAPPED BEHIND A CLOSURE, not re-faked. Http::fake() MERGES
    // stubs rather than replacing them, and the first matching stub answers — so
    // a second fake() call in the same test registers a stub that is never
    // reached. A test that called this twice was therefore asserting against the
    // FIRST payload both times, which is how "a sync may not un-approve hours"
    // passed while the sync was not looking at the flag at all.
    static $record = null;

    $record = array_merge([
        'id' => 'WS-1',
        'employeeId' => 'E-1',
        'employeeRecordId' => 'R-1',
        'jobCodeId' => '9863168',
        'employeeJobCodeRecordId' => 'EJ-1',
        'laborCodes' => ['LC-1', 'LC-2'],
        'timeIn' => '2026-08-13T09:00:00',
        'timeOut' => '2026-08-13T17:30:00',
        'shiftNotes' => ['Late start', 'Covered lunch'],
        // Where the punch happened. The store number is the leading
        // NNNNN-NNNNN; the trailing part names the terminal.
        'punchInInformation' => [
            'applicationType' => 'Standalone Clock',
            'punchLocation' => MAP_STORE_NUMBER.'-0*',
            'description' => MAP_STORE_NUMBER.'-0**',
        ],
        'punchOutInformation' => [],
        'approvals' => [
            ['type' => 'EmployeeApproval', 'approved' => false],
            ['type' => 'ManagerApproval', 'approved' => true, 'approverId' => 'SMHARBOR', 'processedOn' => '2026-08-13T21:31:00'],
            ['type' => 'OtherApproval', 'approved' => false],
        ],
        'geoLocations' => [],
        'missedInPunch' => 'none',
        'missedOutPunch' => 'none',
        'actualTimeIn' => '2026-08-13T09:00:00',
        'actualTimeOut' => '2026-08-13T17:30:00',
        'customFields' => [],
        'createdOnDateTime' => '2026-08-13T09:00:00',
        'updatedOnDateTime' => '2026-08-13T18:00:00',
    ], $overrides);

    // BY REFERENCE. An arrow function would capture the record by value, which
    // is the same bug one level down: the stub would answer with whatever the
    // first call set and never see a later one.
    Http::fake(function () use (&$record) {
        return Http::response(['data' => [$record], 'errors' => [], 'meta' => []], 200);
    });
}

it('resolves the store from the punch location, not from the employee', function () {
    tcpSegment();

    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    // punchInInformation.punchLocation carries the store NUMBER. It is the only
    // field on a real record that says where the work happened — the documented
    // employeeDefaultLocationId never appears, and would have been the person's
    // home store rather than the one they covered.
    expect((int) $segment->store_id)->toBe(MAP_STORE_ID);
});

it('carries TCP\'s manager approval through on create', function () {
    tcpSegment();

    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    // A punch already signed off in TCP arrives signed off here, rather than
    // reappearing on somebody's list of hours to approve.
    expect((bool) $segment->manager_approval)->toBeTrue()
        ->and((bool) $segment->employee_approval)->toBeFalse()
        ->and($segment->approved_at?->format('H:i'))->toBe('21:31')
        // approverId is a TCP user, not one of ours, and approved_by_user_id is
        // a foreign key into our users projection.
        ->and($segment->approved_by_user_id)->toBeNull();
});

/*
| APPROVAL BELONGS TO TCP, and these three pin what that means in both
| directions. It is a reversal: the sync used to skip manager_approval on
| update entirely, on the reasoning that approval was ours once the row
| existed. The consequence was silent and permanent — a punch approved IN TCP
| after we first imported it read "requires approval" in this console forever,
| because nothing ever looked at the flag again.
*/

it('brings back an approval made in TCP after the punch was imported', function () {
    tcpSegment(['approvals' => [['type' => 'ManagerApproval', 'approved' => false]]]);
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    expect((bool) WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail()->manager_approval)
        ->toBeFalse();

    // Somebody signs it off in TCP. The next sweep has to notice, or this row
    // sits on the approval list for the rest of its life.
    tcpSegment(['approvals' => [
        ['type' => 'ManagerApproval', 'approved' => true, 'processedOn' => '2026-08-13T21:31:00'],
    ]]);
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    expect((bool) $segment->manager_approval)->toBeTrue()
        ->and($segment->approved_at?->format('H:i'))->toBe('21:31');
});

it('follows TCP when it withdraws an approval', function () {
    tcpSegment(['approvals' => [['type' => 'ManagerApproval', 'approved' => true]]]);
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    tcpSegment(['approvals' => [['type' => 'ManagerApproval', 'approved' => false]]]);
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    // Kept in step with TCP in both directions. Payroll pays from TCP's answer,
    // so a console still showing "approved" over a withdrawn approval would be
    // telling a manager the day is settled when it is not.
    expect((bool) WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail()->manager_approval)
        ->toBeFalse();
});

it('does not overwrite a local approval that is still on its way to TCP', function () {
    tcpSegment(['approvals' => [['type' => 'ManagerApproval', 'approved' => false]]]);
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    // A manager approves it here. The push is queued, so TCP still answers with
    // its pre-change version for as long as the job is in flight.
    app(App\Services\Scheduling\WorkSegmentService::class)->approve($segment, null);

    tcpSegment(['approvals' => [['type' => 'ManagerApproval', 'approved' => false]]]);
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    // Reading TCP's older answer back would undo the click seconds after it was
    // made, and the queued push would then send a value we no longer hold.
    expect((bool) $segment->fresh()->manager_approval)->toBeTrue();
});

it('maps a real TCP work segment onto our columns', function () {
    tcpSegment(['costCode' => 'CC-A']);

    $report = $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    expect($report['created'])->toBe(1)
        ->and((int) $segment->employee_id)->toBe((int) $this->employee->id)
        // Resolved from punchInInformation.punchLocation.
        ->and((int) $segment->store_id)->toBe(MAP_STORE_ID)
        ->and($segment->cost_code_name)->toBe('CC-A')
        ->and($segment->business_date->toDateString())->toBe('2026-08-13');
});

it('keeps every labor code and every note, not just the first', function () {
    tcpSegment();

    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    // Both arrive as lists against single columns. Taking [0] would silently
    // drop half of a split.
    expect($segment->labor_code)->toBe('LC-1, LC-2')
        ->and($segment->notes)->toBe("Late start\nCovered lunch");
});

it('reads breakLength as a clock duration and derives the hours', function () {
    // Documented but absent from every real record seen. Passed explicitly so
    // the mapping stays covered without the fixture pretending it is normal.
    tcpSegment(['breakLength' => '00:30']);

    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    // 09:00 to 17:30 is 8.5h; the 30 minute break comes off it. TCP sends no
    // hours field, so this is ours to compute.
    expect((int) $segment->break_minutes)->toBe(30)
        ->and((float) $segment->hours)->toBe(8.0);
});

it('also reads breakLength as a plain count of minutes', function () {
    tcpSegment(['breakLength' => '45']);

    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    expect((int) $segment->break_minutes)->toBe(45)
        ->and((float) $segment->hours)->toBe(7.75);
});

it('leaves an open punch without hours', function () {
    tcpSegment(['timeOut' => null]);

    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    // Still clocked in: no hours to approve, which is what keeps the close
    // gate's two blocker categories apart.
    expect($segment->time_out)->toBeNull()
        ->and($segment->hours)->toBeNull();
});

it('drives the incremental window from updatedOnDateTime', function () {
    tcpSegment();

    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    // The field is updatedOnDateTime, not updatedOn. Reading the wrong spelling
    // leaves this null and the --minutes sync can never tell what moved.
    expect($segment->tcp_updated_on)->not->toBeNull()
        ->and($segment->tcp_updated_on->toDateString())->toBe('2026-08-13');
});

it('keeps the whole raw record for the fields that have no column yet', function () {
    tcpSegment(['tracked1' => 7, 'scheduleOrg' => ['id' => 'ORG-1', 'name' => 'Front', 'isActive' => true]]);

    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    // approvals[], tracked1-3, punch information, geoLocations, scheduleOrg and
    // customFields have nowhere to go yet. tcp_payload is why that costs
    // nothing permanent.
    expect($segment->tcp_payload)->toHaveKey('scheduleOrg')
        ->and($segment->tcp_payload['tracked1'])->toBe(7);
});

it('reads a single-object data envelope as one record', function () {
    // The by-id endpoint puts an OBJECT in data where the list endpoint puts an
    // array. Both have to read the same to a caller.
    $client = app(TcpClient::class);

    expect($client->records(['data' => ['id' => 'WS-9'], 'errors' => [], 'meta' => []]))
        ->toHaveCount(1)
        ->and($client->records(['data' => [['id' => 'A'], ['id' => 'B']]]))->toHaveCount(2)
        ->and($client->records(['data' => []]))->toBe([]);
});

it('does not report an unchanged re-pull as a conflict', function () {
    tcpSegment();
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    // Nothing moved at TCP, so there is nothing to apply — which is not the
    // same as refusing something. "held" is what the console reports as "TCP
    // disagreed with a row a human had already touched", and a quiet week
    // announcing that against every row in it is a false alarm at scale.
    $report = $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    expect($report['unchanged'])->toBe(1)
        ->and($report['held'])->toBe(0);
});

it('still holds a local correction against a stale inbound record', function () {
    tcpSegment();
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    app(App\Services\Scheduling\WorkSegmentService::class)->correctTimes(
        $segment,
        $this->bd->combine(MAP_STORE_ID, '2026-08-13', '10:00:00'),
        $this->bd->combine(MAP_STORE_ID, '2026-08-13', '16:00:00'),
    );

    // The push has landed, so the pending guard no longer applies — but TCP's
    // record has not moved since the correction, so it must not win the times
    // back. Without this the manager watches their fix evaporate twice an hour.
    $segment->fresh()->forceFill(['tcp_sync_state' => 'synced'])->save();

    $report = $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    expect($report['held'])->toBe(1)
        ->and($this->bd->toLocal(MAP_STORE_ID, $segment->fresh()->time_in)->format('H:i'))->toBe('10:00');
});

it('decodes the role out of a per-store job code', function () {
    // 37954202 is role 02 at store 3795-42. There are 237 job codes for seven
    // roles, so mapping the code itself would need a row per store per role —
    // and a store opening tomorrow would arrive unmapped.
    $position = App\Models\Position::query()->firstOrFail();
    App\Models\TcpJobCodeRole::query()->create([
        'role_suffix' => '02',
        'tcp_label' => 'Crew Leader',
        'position_id' => $position->id,
        'code_count' => 38,
    ]);

    tcpSegment(['jobCodeId' => '37954202']);
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    expect((int) WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail()->position_id)
        ->toBe((int) $position->id);
});

it('leaves a company-wide pay code unmapped rather than guessing a position', function () {
    App\Models\TcpJobCodeRole::query()->create([
        'role_suffix' => '00',
        'tcp_label' => 'Crew Member',
        'position_id' => App\Models\Position::query()->firstOrFail()->id,
        'code_count' => 38,
    ]);

    // 1000 is "Regular" — how an hour is PAID, not what somebody did. Four
    // digits, so it must not decode to role '00' and borrow that position.
    tcpSegment(['jobCodeId' => '1000']);
    $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    expect(WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail()->position_id)->toBeNull();
});
