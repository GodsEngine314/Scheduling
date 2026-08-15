<?php

use App\Models\Employee;
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
| NAMING THE EMPLOYEES IS THE ONLY WAY, and that is a finding rather than a
| preference. GET /worksegments has NO location filter: verified against the
| live API, where `locations`, `locationIds` and a parameter invented on the
| spot all returned the identical 615 records for a date range.
|
| An earlier version of this file asserted the opposite, because the filter
| looked like it worked — an unrecognised parameter is ignored, not rejected, so
| a store-scoped pull returning the whole estate looks exactly like success.
|
| The employee list has a real gap: it asks who we think works here, so a cover
| shift from another store is missed. That is not a gap this endpoint lets us
| close, and no filter means send NOTHING rather than quietly syncing every
| store's day into one.
|
*/

const MAPPED_STORE_ID = 379500001;

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

/**
 * The query string the PUNCH PULL was called with.
 *
 * Specifically /worksegments, not simply the first request recorded: a
 * store-scoped sync now asks GET /employees who is at the location before it
 * asks for their punches, and that roster call is the first one out.
 */
function sentQuery(): array
{
    $query = [];

    $sent = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->first(fn ($request): bool => str_contains(
            (string) parse_url((string) $request->url(), PHP_URL_PATH), '/worksegments'
        ));

    parse_str((string) parse_url((string) $sent?->url(), PHP_URL_QUERY), $query);

    return $query;
}

/** Did anything go to the punch endpoint at all? */
function pulledPunches(): bool
{
    return collect(Http::recorded())
        ->contains(fn (array $pair): bool => str_contains(
            (string) parse_url((string) $pair[0]->url(), PHP_URL_PATH), '/worksegments'
        ));
}

it('scopes a store-day sync by naming the employees', function () {
    Employee::query()->firstOrFail()->update([
        'primary_store_id' => MAPPED_STORE_ID,
        'tcp_employee_id' => 'E-1',
    ]);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    expect(sentQuery()['employeeIds'] ?? null)->toBe('E-1');
});

it('sends the dates as startDate and stopDate', function () {
    Employee::query()->firstOrFail()->update([
        'primary_store_id' => MAPPED_STORE_ID,
        'tcp_employee_id' => 'E-1',
    ]);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    $query = sentQuery();

    // stopDate, NOT endDate. TCP answers a 400 to endDate:
    // "The 'start date' and 'stop date' must be set."
    expect($query['startDate'] ?? null)->toBe($this->today)
        ->and($query['stopDate'] ?? null)->toBe($this->today)
        ->and($query)->not->toHaveKey('endDate');
});

it('never sends a location filter, because the endpoint has none', function () {
    Employee::query()->firstOrFail()->update([
        'primary_store_id' => MAPPED_STORE_ID,
        'tcp_employee_id' => 'E-1',
    ]);

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    $query = sentQuery();

    // Sending one is worse than useless: TCP ignores it and answers with every
    // store's punches, which would then all be written against this store.
    expect($query)->not->toHaveKey('locations')
        ->and($query)->not->toHaveKey('locationIds')
        // And our own store id is an auth-assigned number TCP has never heard of.
        ->and(implode(',', $query))->not->toContain((string) MAPPED_STORE_ID);
});

it('asks the vendor who is at the store, and pulls punches for people we have never projected', function () {
    // TCP files somebody at this location that our own table has never heard of
    // — hired this morning, or simply never sent by hiring. Scoping the pull to
    // our projection alone would mean their punches are never even asked about.
    Http::fake([
        '*/employees?*' => Http::response(['data' => [
            ['employeeId' => 'E-TCP-ONLY', 'location' => '03795-00001'],
        ]], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    expect(sentQuery()['employeeIds'] ?? null)->toBe('E-TCP-ONLY');
});

it('unions the vendor roster with our own assignments', function () {
    // A cover shift arranged on this side does not change TCP's `location`
    // field, so ours is the only list that knows about it. Neither half is
    // sufficient alone.
    Employee::query()->firstOrFail()->update([
        'primary_store_id' => MAPPED_STORE_ID,
        'tcp_employee_id' => 'E-OURS',
    ]);

    Http::fake([
        '*/employees?*' => Http::response(['data' => [
            ['employeeId' => 'E-THEIRS', 'location' => '03795-00001'],
        ]], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    expect(sentQuery()['employeeIds'] ?? null)->toBe('E-THEIRS,E-OURS');
});

it('still pulls what it can when the roster call fails', function () {
    Employee::query()->firstOrFail()->update([
        'primary_store_id' => MAPPED_STORE_ID,
        'tcp_employee_id' => 'E-1',
    ]);

    Http::fake([
        '*/employees?*' => Http::response('gateway timeout', 504),
        '*' => Http::response(['data' => []], 200),
    ]);

    $this->sync->syncDate($this->today, MAPPED_STORE_ID);

    // Losing the roster must not lose the punches we could still have pulled.
    expect(sentQuery()['employeeIds'] ?? null)->toBe('E-1');
});

it('sends nothing at all when a store has neither a location nor TCP employees', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    // DemoSeeder's store has no integration_identities row and its employees
    // carry no tcp_employee_id, and TCP names nobody at it either.
    $report = $this->sync->syncDate($this->today, DemoSeeder::STORE_ID);

    // An unfiltered request would have returned every store's punches for the
    // day and written them all against this one. The roster lookup that comes
    // first is location-filtered and safe; the punch pull is the one that must
    // not go out naked.
    expect(pulledPunches())->toBeFalse()
        ->and($report['fetched'])->toBe(0)
        ->and($report['skipped'][0]['reason'] ?? null)->toBe('store_has_no_tcp_employees');
});

it('leaves an all-stores sync unfiltered by location', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->sync->syncDate($this->today, null);

    $query = sentQuery();

    expect($query)->not->toHaveKey('locations')
        ->and($query)->not->toHaveKey('employeeIds')
        ->and($query['startDate'] ?? null)->toBe($this->today);
});
