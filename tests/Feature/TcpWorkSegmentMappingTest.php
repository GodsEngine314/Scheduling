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

const MAP_TCP_LOCATION_ID = '9830400';

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->employee = Employee::query()->firstOrFail();
    $this->employee->update(['tcp_employee_id' => 'E-1']);

    $this->sync = app(WorkSegmentSyncService::class);
    $this->bd = app(BusinessDay::class);
});

/** One record in TCP's real envelope. */
function tcpSegment(array $overrides = []): void
{
    Http::fake(['*' => Http::response(['data' => [array_merge([
        'id' => 'WS-1',
        'employeeId' => 'E-1',
        'employeeRecordId' => 'R-1',
        'jobCodeId' => '9863168',
        'costCode' => 'CC-A',
        'laborCodes' => ['LC-1', 'LC-2'],
        'timeIn' => '2026-08-13T09:00:00',
        'timeOut' => '2026-08-13T17:30:00',
        'shiftNotes' => ['Late start', 'Covered lunch'],
        'employeeDefaultLocationId' => MAP_TCP_LOCATION_ID,
        'breakLength' => '00:30',
        'createdOnDateTime' => '2026-08-13T09:00:00',
        'updatedOnDateTime' => '2026-08-13T18:00:00',
    ], $overrides)], 'errors' => [], 'meta' => []], 200)]);
}

it('maps a real TCP work segment onto our columns', function () {
    tcpSegment();

    $report = $this->sync->syncDate('2026-08-13', MAP_STORE_ID);

    $segment = WorkSegment::query()->where('tcp_segment_id', 'WS-1')->firstOrFail();

    expect($report['created'])->toBe(1)
        ->and((int) $segment->employee_id)->toBe((int) $this->employee->id)
        // Resolved from employeeDefaultLocationId through integration_identities,
        // NOT from the store the run was scoped to.
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
    tcpSegment();

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
