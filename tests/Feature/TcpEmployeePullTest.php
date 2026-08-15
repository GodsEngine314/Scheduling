<?php

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Models\Store;
use App\Services\Scheduling\TcpEmployeeReader;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Reading a store's roster back from TCP
|--------------------------------------------------------------------------
|
| The rule this whole feature turns on: `employees` is a PROJECTION of
| hiring.v1.employee.*, so a person TCP knows about and hiring has not sent is
| REPORTED, never created. An invented row there would be erased by the next
| replay, and tcp_employee_id is UNIQUE — so it could also collide with the real
| row when hiring finally sent it, which parks the event.
|
| What the pull may write is integration_identities, which is scheduling-owned
| and survives a replay.
|
*/

const PULL_STORE_ID = 379500001;

const PULL_TCP_LOCATION_ID = '9830400';

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->reader = app(TcpEmployeeReader::class);

    // Every route requires a token the auth service issued.
    signIn();
});

/** TCP replies with one page of employees. */
function fakeRoster(array $employees): void
{
    Http::fake(['*' => Http::response(['data' => $employees], 200)]);
}

function tcpIdentityFor(int $employeeId): ?IntegrationIdentity
{
    return IntegrationIdentity::query()
        ->forEntity(IntegrationEntityType::Employee, $employeeId, IntegrationSystem::Tcp)
        ->first();
}

it('filters by the store NUMBER, not the numeric location id', function () {
    fakeRoster([]);

    $this->reader->forStore(PULL_STORE_ID);

    Http::assertSent(function ($request) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        // Verified against the live API: `locationIds=9830400` is IGNORED and
        // returns all 430 employees in the company, looking exactly like a
        // successful store-scoped pull. `locations=03795-00001` returns 20.
        return str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/employees')
            && ($query['locations'] ?? null) === '03795-00001'
            && ! array_key_exists('locationIds', $query);
    });
});

it('sends our own store id nowhere near the vendor', function () {
    fakeRoster([]);

    $this->reader->forStore(PULL_STORE_ID);

    Http::assertSent(function ($request) {
        // 379500001 is an id auth assigned; TCP has never heard of it.
        return ! str_contains($request->url(), (string) PULL_STORE_ID);
    });
});

it('links an employee hiring already gave us to their TCP id', function () {
    $employee = Employee::query()->firstOrFail();
    $employee->update(['tcp_employee_id' => 'E-77']);

    fakeRoster([
        ['employeeId' => 'E-77', 'employeeRecordId' => 'R-77', 'firstName' => 'Ada', 'lastName' => 'Okafor'],
    ]);

    $report = $this->reader->forStore(PULL_STORE_ID);

    $identity = tcpIdentityFor((int) $employee->id);

    // The mapping now lives in the scheduling-owned table, so it survives a
    // projection rebuild that would wipe employees.tcp_employee_id.
    expect($report['mapped'])->toBe(1)
        ->and($report['unmatched'])->toBe([])
        ->and($identity)->not->toBeNull()
        ->and($identity->external_id)->toBe('E-77')
        ->and($identity->external_record_id)->toBe('R-77')
        ->and($identity->isSynced())->toBeTrue();
});

it('reports somebody TCP knows and hiring has not sent, and does NOT create them', function () {
    $before = Employee::query()->count();

    fakeRoster([
        ['employeeId' => 'E-STRANGER', 'firstName' => 'Nia', 'lastName' => 'Fournier'],
    ]);

    $report = $this->reader->forStore(PULL_STORE_ID);

    // Named, so the gap is actionable — but the projection is untouched.
    expect($report['unmatched'])->toHaveCount(1)
        ->and($report['unmatched'][0]['tcp_employee_id'])->toBe('E-STRANGER')
        ->and($report['unmatched'][0]['name'])->toBe('Nia Fournier')
        ->and($report['mapped'])->toBe(0)
        ->and(Employee::query()->count())->toBe($before);
});

it('counts an unchanged mapping as already linked rather than rewriting it', function () {
    $employee = Employee::query()->firstOrFail();
    $employee->update(['tcp_employee_id' => 'E-77']);

    fakeRoster([['employeeId' => 'E-77', 'firstName' => 'Ada', 'lastName' => 'Okafor']]);
    $this->reader->forStore(PULL_STORE_ID);

    fakeRoster([['employeeId' => 'E-77', 'firstName' => 'Ada', 'lastName' => 'Okafor']]);
    $report = $this->reader->forStore(PULL_STORE_ID);

    expect($report['already_mapped'])->toBe(1)
        ->and($report['mapped'])->toBe(0);
});

it('keeps a TCP id with the employee already mapped to it', function () {
    $employees = Employee::query()->orderBy('id')->take(2)->get();

    // Confirmed against the FIRST employee, in the scheduling-owned table.
    IntegrationIdentity::query()->create([
        'entity_type' => IntegrationEntityType::Employee,
        'entity_id' => $employees[0]->id,
        'system' => IntegrationSystem::Tcp,
        'external_id' => 'E-CLASH',
        'sync_state' => 'synced',
    ]);

    // The SECOND appears to hold it too, via the PROJECTED column — which is
    // exactly the disagreement a stale hiring payload would produce.
    $employees[1]->update(['tcp_employee_id' => 'E-CLASH']);

    fakeRoster([['employeeId' => 'E-CLASH', 'firstName' => 'Who', 'lastName' => 'Knows']]);

    $report = $this->reader->forStore(PULL_STORE_ID);

    // The owned table wins. Letting the projected column reassign the id would
    // move somebody's punches onto the wrong person, and would break the
    // UNIQUE(system, entity_type, external_id) constraint on the way.
    expect($report['already_mapped'])->toBe(1)
        ->and(tcpIdentityFor((int) $employees[0]->id)->external_id)->toBe('E-CLASH')
        ->and(tcpIdentityFor((int) $employees[1]->id))->toBeNull();
});

it('sends nothing when the store has no store number to filter on', function () {
    fakeRoster([]);

    // A store row with no number at all — nothing to put in the filter.
    Store::query()->whereKey(DemoSeeder::STORE_ID)->update(['store_number' => '']);

    $report = $this->reader->forStore(DemoSeeder::STORE_ID);

    // An unfiltered GET /employees returns the whole company — measured at 430 —
    // every one of whom would then look like they work at this store.
    Http::assertNothingSent();

    expect(array_column($report['skipped'], 'reason'))->toContain('store_has_no_store_number');
});

it('sends nothing at all when TCP is not configured', function () {
    config()->set('tcp.static_token', '');

    fakeRoster([]);

    $report = $this->reader->forStore(PULL_STORE_ID);

    Http::assertNothingSent();

    expect(array_column($report['skipped'], 'reason'))->toContain('tcp_not_configured');
});

// ── the board only pulls when the store actually changes ────────────────

/*
 * Counted as "does landing again cost more", not as a fixed total.
 *
 * The first render of a store now costs more than one call: the board pulls the
 * roster here, and BoardController also asks WorkSegmentSyncService for the
 * day's punches, which resolves the store's TCP roster for itself. Asserting an
 * absolute number would pin that arrangement rather than the rule these tests
 * exist for — which is that landing on the SAME store again is free.
 */
it('pulls once when the board lands on a store, not on every render', function () {
    fakeRoster([]);

    $this->get('/board?store='.PULL_STORE_ID)->assertOk();
    $afterFirst = count(Http::recorded());

    $this->get('/board?store='.PULL_STORE_ID.'&date='.now()->addDay()->toDateString())->assertOk();
    $this->get('/board?store='.PULL_STORE_ID)->assertOk();

    // Paging through dates on one store must not put a vendor round trip in
    // front of the board every time.
    expect(count(Http::recorded()))->toBe($afterFirst);
});

it('pulls again when the board moves to a different store', function () {
    fakeRoster([]);

    $this->get('/board?store='.PULL_STORE_ID)->assertOk();
    $afterFirst = count(Http::recorded());

    $this->get('/board?store=379500002')->assertOk();

    // A different store is a different roster, and nothing already fetched
    // answers for it.
    expect(count(Http::recorded()))->toBeGreaterThan($afterFirst);
});

it('still renders the board when TCP is unreachable', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response('gateway blew up', 502)]);

    // The pull is a convenience. The schedule is not — a vendor outage must
    // cost a message, never the screen.
    $this->get('/board?store='.PULL_STORE_ID)
        ->assertOk()
        ->assertSee('Store 03795-00001')
        ->assertSee('Could not read the roster from TCP');
});
