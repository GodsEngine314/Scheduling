<?php

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Services\Scheduling\WorkSegmentSyncService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| How a store-day sync is scoped at the vendor
|--------------------------------------------------------------------------
|
| Asking TCP for "this store's punches on this date" can be done two ways, and
| they are not the same question. The store's own location id asks where the
| punch happened; naming its employees asks who we think works there, which
| misses a cover shift from another store and wrongly pulls in one of ours
| covering elsewhere.
|
| So: the location id when we have it, the employee list only when we do not,
| and NEITHER means send nothing rather than quietly widening a one-store sync
| into the whole estate's day.
|
*/

/** The store TCP knows as 9830400. */
const MAPPED_STORE_ID = 379500001;

const MAPPED_TCP_LOCATION_ID = '9830400';

beforeEach(function () {
    // The default 'oauth' mode makes the client exchange credentials first and
    // die on an unset client_id before it ever reaches /worksegments.
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    // Before the seed: phpunit runs the queue synchronously, so the seeder's
    // own TCP pushes would otherwise execute inline.
    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
    $this->today = app(BusinessDay::class)->toLocal(DemoSeeder::STORE_ID, now())->toDateString();

    $this->sync = app(WorkSegmentSyncService::class);
});

/** The query string TCP was actually called with. */
function sentQuery(): array
{
    $query = [];
    $sent = Http::recorded()[0][0] ?? null;

    parse_str((string) parse_url((string) $sent?->url(), PHP_URL_QUERY), $query);

    return $query;
}

it('scopes a store-day sync by the TCP location id, not by naming employees', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    $query = sentQuery();

    // Where the punch happened, asked directly. One value, so the vendor's
    // 20-value cap never comes into it.
    expect($query['locationIds'] ?? null)->toBe(MAPPED_TCP_LOCATION_ID)
        ->and($query)->not->toHaveKey('employeeIds')
        ->and($query['startDate'] ?? null)->toBe($this->today)
        ->and($query['endDate'] ?? null)->toBe($this->today);
});

it('sends our store id nowhere near the vendor', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    // 379500001 is an id auth assigned and TCP has never heard of. Leaking it
    // into the filter would return somebody else's punches, or none, and look
    // like an empty day either way.
    expect(sentQuery()['locationIds'] ?? null)->not->toBe((string) MAPPED_STORE_ID);
});

it('falls back to naming employees when the store has no TCP location mapping', function () {
    IntegrationIdentity::query()
        ->forEntity(IntegrationEntityType::Store, MAPPED_STORE_ID, IntegrationSystem::Tcp)
        ->delete();

    Employee::query()->firstOrFail()->update([
        'primary_store_id' => MAPPED_STORE_ID,
        'tcp_employee_id' => 'E-1',
    ]);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    $query = sentQuery();

    expect($query)->not->toHaveKey('locationIds')
        ->and($query['employeeIds'] ?? null)->toBe('E-1');
});

it('sends nothing at all when a store has neither a location nor TCP employees', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    // DemoSeeder's store has no integration_identities row and its employees
    // carry no tcp_employee_id.
    $report = $this->sync->syncDate($this->today, DemoSeeder::STORE_ID);

    // An unfiltered request would have returned every store's punches for the
    // day and written them all against this one.
    Http::assertNothingSent();

    expect($report['fetched'])->toBe(0)
        ->and($report['skipped'][0]['reason'] ?? null)->toBe('store_has_no_tcp_location_or_employees');
});

it('leaves an all-stores sync unfiltered by location', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->sync->syncDate($this->today, null);

    $query = sentQuery();

    expect($query)->not->toHaveKey('locationIds')
        ->and($query)->not->toHaveKey('employeeIds')
        ->and($query['startDate'] ?? null)->toBe($this->today);
});
