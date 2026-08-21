<?php

use App\Models\HumanitySchedule;
use App\Models\IntegrationIdentity;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpJobCodeRole;
use App\Services\Scheduling\SchedulePublisher;
use App\Services\Scheduling\ShiftService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What actually goes on the wire to Humanity
|--------------------------------------------------------------------------
|
| PublishCycleTest proves the CYCLE: nothing leaves until publish, a first
| publish is a POST, an edit is a PUT over the same shift. Every one of those
| assertions passed while the request body was rejected by the vendor, because a
| faked Humanity answers 200 to anything.
|
| So this file asserts the BODY, against the vendor's own reference:
|
|   POST   /shifts        start_time, end_time, start_date, end_date and
|                         schedule are REQUIRED. employee_id is "a
|                         comma-separated employee IDs which will be assigned to
|                         a shift". type is 0 Standard / 1 Open.
|   PUT    /shifts/{id}   add / remove carry staffing. employee_id "works only
|                         in conjunction with parameter copy_to".
|   DELETE /shifts/{id}   rule is 'following' or 'all'.
|   auth                  ?access_token=, and every body form-encoded.
|
| Formats: dates 'Y-m-d', times 'g:ia' — "5:00pm". Four fields, not two
| timestamps, which is what the original implementation sent.
|
*/

beforeEach(function () {
    config()->set('humanity.oauth.client_id', 'cid');
    config()->set('humanity.oauth.client_secret', 'secret');
    config()->set('humanity.oauth.username', 'user');
    config()->set('humanity.oauth.password', 'pass');

    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->today = app(BusinessDay::class)->toLocal(DemoSeeder::STORE_ID, now())->toDateString();
    $this->publisher = app(SchedulePublisher::class);
    $this->shifts = app(ShiftService::class);
    $this->position = Position::query()->firstOrFail();

    $this->employee = (int) Shift::query()
        ->whereNotNull('employee_id')
        ->orderBy('id')
        ->firstOrFail()
        ->employee_id;

    IntegrationIdentity::query()->create([
        'entity_type' => 'employee',
        'entity_id' => $this->employee,
        'system' => 'humanity',
        'external_id' => '9260196',
        'sync_state' => 'synced',
    ]);

    IntegrationIdentity::query()->create([
        'entity_type' => 'store',
        'entity_id' => DemoSeeder::STORE_ID,
        'system' => 'humanity',
        'external_id' => '1355181',
        'sync_state' => 'synced',
    ]);

    HumanitySchedule::query()->create([
        'schedule_id' => '4086921',
        'store_id' => DemoSeeder::STORE_ID,
        'position_id' => $this->position->id,
        'name' => 'Crew Member - 3795-10',
    ]);
});

/** Humanity answers a token, then echoes a shift with an id. */
function fakeWire(?array $shiftBody = null): void
{
    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*' => Http::response(['status' => 1, 'data' => $shiftBody ?? ['id' => '77001']], 200),
    ]);
}

/** A draft nobody has punched against, at a known local wall clock. */
function wireShift(string $start = '17:00', string $end = '21:00', mixed $employeeId = false): Shift
{
    $test = test();
    $date = $test->today;
    $endDate = $end <= $start ? now()->parse($date)->addDay()->toDateString() : $date;

    return $test->shifts->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employeeId === false ? $test->employee : $employeeId,
        'position_id' => $test->position->id,
        'start_at_local' => "{$date} {$start}:00",
        'end_at_local' => "{$endDate} {$end}:00",
    ]);
}

/** The parsed body of the one POST /shifts that was sent. */
function sentCreateBody(): array
{
    $recorded = collect(Http::recorded())
        ->first(fn (array $pair): bool => $pair[0]->method() === 'POST'
            && parse_url($pair[0]->url(), PHP_URL_PATH) === '/api/v2/shifts');

    expect($recorded)->not->toBeNull('no POST /shifts was sent');

    return $recorded[0]->data();
}

// ── the create body ─────────────────────────────────────────────────────

it('sends the five required fields, in the formats Humanity documents', function () {
    fakeWire();

    $this->publisher->push(wireShift('17:00', '21:00'));

    $body = sentCreateBody();

    // Four fields, not two timestamps. This is the correction that matters:
    // start_date and end_date were absent entirely, and the times were
    // 'Y-m-d H:i:s'.
    expect($body)->toHaveKeys(['start_date', 'end_date', 'start_time', 'end_time', 'schedule'])
        ->and($body['start_date'])->toBe($this->today)
        ->and($body['end_date'])->toBe($this->today)
        ->and($body['start_time'])->toBe('5:00pm')
        ->and($body['end_time'])->toBe('9:00pm')
        // From the catalogue, not from integration_identities.
        ->and((string) $body['schedule'])->toBe('4086921');
});

it('form-encodes the body, because a JSON one is parsed as no parameters at all', function () {
    fakeWire();

    $this->publisher->push(wireShift());

    Http::assertSent(fn ($request): bool => $request->method() !== 'POST'
        || ! str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/shifts')
        || str_contains(
            strtolower(implode(',', $request->header('Content-Type'))),
            'application/x-www-form-urlencoded',
        ));
});

it('carries the token as access_token, which is the name Humanity reads', function () {
    fakeWire();

    $this->publisher->push(wireShift());

    Http::assertSent(function ($request): bool {
        if (! str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/api/v2/shifts')) {
            return true;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        // '_token' was the guess, and a token under a name the vendor does not
        // read presents as a 401 on a perfectly good credential.
        return ($query['access_token'] ?? null) === 'tok' && ! array_key_exists('_token', $query);
    });
});

it('staffs the new shift in the create itself, rather than leaving it open for a second call', function () {
    fakeWire();

    $this->publisher->push(wireShift());

    // "A comma-separated employee IDs which will be assigned to a shift". One
    // string: the body is form-encoded and an array would go as employee_id[0]=
    expect(sentCreateBody()['employee_id'])->toBe('9260196');

    // The response echoed no roster, so nothing second-guesses the documented
    // parameter. One request, and the shift is never briefly unstaffed.
    Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
});

it('rolls end_date to the next day for an overnight shift', function () {
    fakeWire();

    // 21:00 -> 01:00. business_date still files it under the day it started;
    // end_date is what tells Humanity when it finishes.
    $this->publisher->push(wireShift('21:00', '01:00'));

    $body = sentCreateBody();
    $tomorrow = now()->parse($this->today)->addDay()->toDateString();

    expect($body['start_date'])->toBe($this->today)
        ->and($body['start_time'])->toBe('9:00pm')
        ->and($body['end_date'])->toBe($tomorrow)
        ->and($body['end_time'])->toBe('1:00am');
});

it('publishes an unassigned shift as an OPEN shift with nobody named', function () {
    fakeWire();

    $this->publisher->push(wireShift('11:00', '14:00', employeeId: null));

    $body = sentCreateBody();

    // 1 -> Open. Published as Standard-with-nobody-on-it it would be invisible
    // to the employees who could pick it up.
    expect((int) $body['type'])->toBe(1)
        ->and((int) $body['needed'])->toBe(1)
        ->and($body)->not->toHaveKey('employee_id');
});

it('publishes an assigned shift as Standard', function () {
    fakeWire();

    $this->publisher->push(wireShift());

    expect((int) sentCreateBody()['type'])->toBe(0);
});

it('asks for no extra bodies on a staffed shift, which is what real shifts read', function () {
    fakeWire();

    $this->publisher->push(wireShift());

    // A live GET /shifts shows every real shift in this account as
    // type 0, needed 0, location 0. `needed` counts slots to FILL, and a shift
    // with its person on it has none — sending 1 asked the store for one more
    // body than it wanted.
    expect((int) sentCreateBody()['needed'])->toBe(0);
});

it('does not name the store location, which is Humanity\'s remote-location override', function () {
    fakeWire();

    $this->publisher->push(wireShift());

    // The shift's real location comes from its schedule, which is per store in
    // this account. Sending it again through `location` would mark the store's
    // whole week as worked somewhere else.
    expect(sentCreateBody())->not->toHaveKey('location');
});

it('names the location when the store is configured to send one', function () {
    config()->set('humanity.send_shift_location', true);
    fakeWire();

    $this->publisher->push(wireShift());

    expect((string) sentCreateBody()['location'])->toBe('1355181');
});

// ── the schedule guard ──────────────────────────────────────────────────

it('refuses a role neither TCP nor Humanity has, and says creating it in Humanity will not help', function () {
    fakeWire();

    // A role with no TCP job code. Since the catalogue is keyed by job code,
    // Humanity was never going to have a schedule for it either.
    $other = Position::query()->create(['label' => 'Dishwasher']);
    $shift = wireShift();
    $shift->forceFill(['position_id' => $other->id])->save();

    $result = $this->publisher->publishShift($shift->fresh());

    // Refused HERE, naming the store and the role, rather than after a round
    // trip that reports a vendor error about a required field.
    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toContain('TCP has no job code for that store and role')
        ->and($result['error'])->toContain('creating one there would not help')
        ->and($shift->fresh()->publish_state->value)->toBe('failed')
        ->and($shift->fresh()->humanity_shift_id)->toBeNull();

    Http::assertNotSent(fn ($request): bool => str_contains(
        (string) parse_url($request->url(), PHP_URL_PATH),
        '/api/v2/shifts',
    ));
});

it('names the job code to set when TCP has one and no Humanity position carries it', function () {
    fakeWire();

    // TCP knows this store and role; Humanity's position just has no job code
    // on it. That IS fixable in Humanity, and the message has to say how.
    //
    // The demo store's number is a bare "4821", which storeKeyFor() cannot turn
    // into a code — franchise-store is the only shape a job code has. Give it a
    // real-looking number so this test exercises the branch it is about rather
    // than falling into the "no job code at all" one.
    Store::query()->whereKey(DemoSeeder::STORE_ID)->update(['store_number' => '4821-01']);

    TcpJobCodeRole::query()->create([
        'role_suffix' => '01',
        'tcp_label' => 'Fixture role',
        'position_id' => $this->position->id,
        'code_count' => 1,
    ]);
    TcpJobCode::query()->create([
        'job_code_id' => '48210101',
        'store_key' => TcpJobCodeRole::storeKeyFor('4821-01'),
        'role_suffix' => '01',
        'description' => 'Fixture',
    ]);

    // POPULATED but missing THIS pair. Emptying it entirely would hit the
    // "catalogue is empty — run the seeder" branch instead, which is a different
    // message for a different problem.
    HumanitySchedule::query()->update(['position_id' => null]);

    $result = $this->publisher->publishShift(wireShift());

    expect($result['error'])->toContain('48210101')
        ->and($result['error'])->toContain('humanity:export-positions');
});

it('says the catalogue is empty when it is, because that is a different problem', function () {
    fakeWire();

    HumanitySchedule::query()->delete();

    $result = $this->publisher->publishShift(wireShift());

    // "Run the seeder" is a fixable instruction. "This store does not staff
    // Drivers in Humanity" is a decision somebody has to make in Humanity.
    expect($result['error'])->toContain('schedule catalogue is empty')
        ->and($result['error'])->toContain('HumanitySeeder');
});

it('refuses a shift with no position at all', function () {
    fakeWire();

    $shift = wireShift();
    $shift->forceFill(['position_id' => null])->save();

    $result = $this->publisher->publishShift($shift->fresh());

    expect($result['error'])->toContain('has no position');
});

// ── the update body ────────────────────────────────────────────────────

it('moves staffing with add and remove on an update, never employee_id', function () {
    // ONE fake for both phases. Http::fake() APPENDS stubs and the first match
    // wins, so re-faking a '*' catch-all later does not replace this one — the
    // GET has to be answered by the same closure.
    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*' => function ($request) {
            $shift = ['id' => '77001'];

            // Only the READ reports a roster, and it reports the OLD one: the
            // delta cannot know who to take off otherwise. The create answers
            // without an `employees` key, which is the silence pushCreate is
            // meant to trust.
            if ($request->method() === 'GET') {
                $shift['employees'] = [['id' => '9260196']];
            }

            return Http::response(['status' => 1, 'data' => $shift], 200);
        },
    ]);

    $shift = wireShift();
    $this->publisher->push($shift);

    // Reassign to somebody else, then re-publish.
    $second = Shift::query()->whereNotNull('employee_id')
        ->where('employee_id', '!=', $this->employee)->firstOrFail()->employee_id;

    IntegrationIdentity::query()->create([
        'entity_type' => 'employee',
        'entity_id' => $second,
        'system' => 'humanity',
        'external_id' => '9259918',
        'sync_state' => 'synced',
    ]);

    $this->publisher->unpublish($shift->fresh());
    app(ShiftService::class)->update($shift->fresh(), ['employee_id' => $second]);

    $this->publisher->push($shift->fresh());

    $puts = collect(Http::recorded())
        ->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT')
        ->map(fn (array $pair): array => $pair[0]->data());

    expect($puts)->not->toBeEmpty();

    $staffing = $puts->first(fn (array $body): bool => isset($body['add']) || isset($body['remove']));

    expect($staffing)->not->toBeNull()
        ->and($staffing['add'])->toBe('9259918')
        ->and($staffing['remove'])->toBe('9260196');

    // employee_id on a PUT is honoured only alongside copy_to. Sent alone it is
    // accepted and ignored, which is a shift that looks reassigned here and is
    // still rostered to the old employee there.
    $puts->each(fn (array $body) => expect($body)->not->toHaveKey('employee_id'));
});

it('adds the missing people when a create comes back reporting an empty roster', function () {
    // Humanity says, in the create response itself, that the shift has nobody on
    // it. Silence would be trusted; an explicit empty roster is a disagreement.
    fakeWire(['id' => '77001', 'employees' => []]);

    $this->publisher->push(wireShift());

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && ($request->data()['add'] ?? null) === '9260196');
});

// ── transient failures ─────────────────────────────────────────────────

it('retries a token endpoint that could not be reached, instead of failing the publish', function () {
    Sleep::fake();

    $attempts = 0;

    Http::fake([
        '*oauth2/token*' => function () use (&$attempts) {
            $attempts++;

            // Unreachable once, then fine. Observed for real against the vendor:
            // "could not be reached", then the identical call succeeding.
            if ($attempts === 1) {
                throw new ConnectionException('cURL error 28: Resolving timed out');
            }

            return Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200);
        },
        '*' => Http::response(['status' => 1, 'data' => ['id' => '77001']], 200),
    ]);

    $shift = wireShift();
    $result = $this->publisher->publishShift($shift);

    // The token fetch lives inside send(), and it threw an IntegrationException
    // rather than a ConnectionException — so it used to sail past the retry loop
    // and fail the whole publish on the first attempt with retry.attempts at 3.
    expect($result['status'])->toBe('created')
        ->and($attempts)->toBe(2)
        ->and($shift->fresh()->humanity_shift_id)->toBe('77001');
});

it('does not retry a rejected credential, because repeating a bad login locks accounts', function () {
    Sleep::fake();

    $attempts = 0;

    Http::fake([
        '*oauth2/token*' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['error' => 'invalid_grant'], 401);
        },
    ]);

    $result = $this->publisher->publishShift(wireShift());

    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toContain('rejected the credentials')
        // ONCE. A wrong password is wrong in five minutes too.
        ->and($attempts)->toBe(1);
});

// ── the read path ──────────────────────────────────────────────────────

it('exports the position catalogue with a GET, and never writes the token to disk', function () {
    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*' => Http::response([
            'status' => 1,
            'data' => [[
                'id' => 4086921,
                'name' => 'Crew Member - 3795-10',
                'location' => ['id' => 1355181, 'name' => '03795-00010'],
            ]],
            // Every Humanity response carries one of these. Dumping the response
            // verbatim would leave a WORKING CREDENTIAL in a file on disk.
            'token' => 'a-live-access-token',
        ], 200),
    ]);

    $path = storage_path('app/integrations/humanity-positions-export-test.json');
    @unlink($path);

    $this->artisan('humanity:export-positions', ['--path' => $path])->assertSuccessful();

    $written = (string) file_get_contents($path);
    @unlink($path);

    expect($written)->toContain('4086921')
        ->and($written)->toContain('Crew Member - 3795-10')
        ->and($written)->not->toContain('a-live-access-token');

    // READ ONLY. The token exchange is the only POST; nothing touches /shifts.
    Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET'
        && ! str_contains($request->url(), 'oauth2/token'));
});

it('writes nothing when the account cannot read positions', function () {
    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*' => Http::response(['status' => 1, 'data' => []], 200),
    ]);

    $path = storage_path('app/integrations/humanity-positions-empty-test.json');
    @unlink($path);

    // An empty answer is far likelier to be a permissions problem than an
    // account with no positions, and writing the file would only move the
    // confusion into the seeder.
    $this->artisan('humanity:export-positions', ['--path' => $path])->assertFailed();

    expect(is_file($path))->toBeFalse();
});

// ── delete ─────────────────────────────────────────────────────────────

it('carries the delete rule, and defaults to the survivable one', function () {
    fakeWire();

    $shift = wireShift();
    $this->publisher->push($shift);

    fakeWire();
    $this->publisher->withdraw($shift->fresh());

    // 'all' wipes occurrences already in the past, so 'following' is the
    // default. Sent as query AND body: the spec says body, and a PHP endpoint
    // reading request parameters may only see the query.
    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'DELETE') {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['rule'] ?? null) === 'following'
            && ($request->data()['rule'] ?? null) === 'following';
    });

    expect($shift->fresh()->publish_state->value)->toBe('unpublished')
        ->and($shift->fresh()->humanity_shift_id)->toBeNull();
});
