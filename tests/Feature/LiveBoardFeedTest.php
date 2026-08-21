<?php

use App\Models\Employee;
use App\Models\WorkSegment;
use App\Services\Scheduling\LiveSegmentFeed;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The board keeps itself current
|--------------------------------------------------------------------------
|
| What this replaced: "Pull the week's actual hours". Hours only arrived when
| somebody found the button and pressed it, and a board nobody had pressed it
| for looked exactly as settled as one that was up to date — so forgetting was
| invisible, which is the worst property a number on a payroll screen can have.
|
| TCP has no webhook. GET /worksegments is the whole surface, so "live" here
| means polled, and the guarantees worth pinning are the ones that make polling
| affordable and honest:
|
|   the fingerprint moves for every change the grid can show, and for nothing
|   else — it is what tells a page to reload itself, so a fingerprint that
|   flapped would reload a screen somebody is reading
|
|   concurrent viewers cost ONE vendor call, not one each
|
|   a vendor outage is reported in the payload with a 200, because a polling
|   loop that receives an error status is a polling loop that stops
|
*/

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
    Cache::flush();

    // syncRange() names the people rather than the store — GET /worksegments has
    // no location filter — so without a TCP id on somebody it returns
    // store_has_no_tcp_employees and never asks for punches at all.
    Employee::query()
        ->where('primary_store_id', DemoSeeder::STORE_ID)
        ->get()
        ->each(fn ($employee, $i) => $employee->forceFill([
            'tcp_employee_id' => (string) (55500 + $i),
        ])->save());

    $this->from = '2026-08-11';
    $this->to = '2026-08-17';

    signIn();
});

function livePoll(?array $overrides = null)
{
    return test()->getJson(route('board.live', array_merge([
        'store' => DemoSeeder::STORE_ID,
        'from' => test()->from,
        'to' => test()->to,
    ], $overrides ?? [])));
}

/**
 * One finished, unapproved punch on a given business date at the demo store.
 *
 * DemoSeeder places its punches around today; this file polls a fixed week, so
 * the rows these assertions turn on are built rather than found.
 */
function segmentInRange(string $businessDate): WorkSegment
{
    $existing = WorkSegment::query()->firstOrFail();

    return WorkSegment::query()->create([
        'employee_id' => $existing->employee_id,
        'store_id' => DemoSeeder::STORE_ID,
        'position_id' => $existing->position_id,
        'business_date' => $businessDate,
        'time_in' => now()->parse($businessDate.' 14:00:00'),
        'time_out' => now()->parse($businessDate.' 22:00:00'),
        'hours' => 8,
        'manager_approval' => false,
    ]);
}

function segmentRequests(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'worksegments'))
        ->count();
}

// ── the poll ────────────────────────────────────────────────────────────

it('reports the range and refreshes it from TCP', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    livePoll()
        ->assertOk()
        ->assertJsonStructure([
            'fingerprint', 'punches', 'unapproved', 'open',
            'checked_seconds_ago', 'checking', 'error', 'poll_seconds',
        ]);

    // The poll is what makes the board current; a poll that only counted rows
    // would leave the button's job undone.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'worksegments'));
});

it('asks TCP once for a range however many pollers there are', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    // Four managers, nine tabs. Behind a lock this is one request, not nine —
    // which is the whole reason a twenty-second interval is affordable.
    foreach (range(1, 9) as $ignored) {
        livePoll()->assertOk();
    }

    expect(segmentRequests())->toBe(1);
});

it('asks TCP again once the range has gone stale', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    config()->set('tcp.live.refresh_seconds', 5);

    livePoll()->assertOk();
    expect(segmentRequests())->toBe(1);

    // Reaching past the interval rather than sleeping through it: the state is
    // a cache entry carrying the unix second it was written.
    $key = 'tcp:live_segments:'.DemoSeeder::STORE_ID.':'.$this->from.':'.$this->to;
    $state = Cache::get($key);
    $state['synced_at'] = time() - 60;
    Cache::put($key, $state, now()->addDay());

    livePoll()->assertOk();
    expect(segmentRequests())->toBe(2);
});

it('polls a different week as a range of its own', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    livePoll()->assertOk();
    livePoll(['from' => '2026-08-18', 'to' => '2026-08-24'])->assertOk();

    expect(segmentRequests())->toBe(2);
});

it('costs one vendor call to open a week and start polling it', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    // Rendering the board fetches the range so the first paint is truthful...
    test()->get(route('board.week', [
        'store' => DemoSeeder::STORE_ID, 'week' => $this->from, 'view' => 'actual',
    ]))->assertOk();

    $afterRender = segmentRequests();
    expect($afterRender)->toBeGreaterThan(0);

    // ...and the heartbeat that starts half a second later must see that as a
    // read already made. The render used to keep a session key and the poll a
    // cache key, and neither knew about the other, so every navigation paid for
    // the same week twice.
    livePoll()->assertOk();

    expect(segmentRequests())->toBe($afterRender);
});

it('shares a range read between two people, not just two tabs', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    livePoll()->assertOk();
    $afterFirst = segmentRequests();

    // A different session entirely. The freshness record lives in the cache
    // rather than in a session, which is what makes this free — the second
    // manager to open Tuesday does not pay for the first one's round trip.
    test()->flushSession();
    signIn(userId: null);

    livePoll()->assertOk();

    expect(segmentRequests())->toBe($afterFirst);
});

it('does not put a vendor call in front of a redirect the heartbeat already covers', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    config()->set('tcp.live.refresh_seconds', 20);
    config()->set('tcp.live.render_max_age_seconds', 300);

    $week = fn () => test()->get(route('board.week', [
        'store' => DemoSeeder::STORE_ID, 'week' => $this->from, 'view' => 'actual',
    ]))->assertOk();

    $week();
    $afterFirst = segmentRequests();

    // Twenty-five seconds on: past the HEARTBEAT's interval, nowhere near the
    // RENDER's. Every approve and correction redirects onto this render, and a
    // person is sitting through the wait, so it must not pay for a round trip
    // the background poll is already making.
    $key = 'tcp:live_segments:'.DemoSeeder::STORE_ID.':'.$this->from.':'.$this->to;
    $state = Cache::get($key);
    $state['synced_at'] = time() - 25;
    Cache::put($key, $state, now()->addDay());

    $week();

    expect(segmentRequests())->toBe($afterFirst);

    // The poll, on its own tighter interval, DOES go and look.
    livePoll()->assertOk();
    expect(segmentRequests())->toBeGreaterThan($afterFirst);
});

it('always reads a range it has never read before, however lax the threshold', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    config()->set('tcp.live.render_max_age_seconds', 86400);

    // The one case where an empty grid really could be a lie: nothing has ever
    // asked TCP about this week. No threshold may skip that.
    test()->get(route('board.week', [
        'store' => DemoSeeder::STORE_ID, 'week' => '2026-09-01', 'view' => 'actual',
    ]))->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'worksegments'));
});

// ── the fingerprint ─────────────────────────────────────────────────────

it('holds the fingerprint still when nothing has changed', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $first = livePoll()->json('fingerprint');
    $second = livePoll()->json('fingerprint');

    // A fingerprint that flapped would reload the page out from under whoever
    // is reading it, every few seconds, forever.
    expect($second)->toBe($first);
});

it('moves the fingerprint when a punch arrives', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $before = livePoll()->json('fingerprint');

    $existing = WorkSegment::query()->firstOrFail();
    WorkSegment::query()->create([
        'employee_id' => $existing->employee_id,
        'store_id' => DemoSeeder::STORE_ID,
        'position_id' => $existing->position_id,
        'business_date' => $this->from,
        'time_in' => now()->parse($this->from.' 14:00:00'),
        'time_out' => now()->parse($this->from.' 22:00:00'),
        'hours' => 8,
    ]);

    expect(livePoll()->json('fingerprint'))->not->toBe($before);
});

it('moves the fingerprint when hours are approved', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    // DemoSeeder builds its punches around today, which is not the fixed week
    // this file polls, so the row under test is made here.
    $segment = segmentInRange($this->from);

    $before = livePoll()->json('fingerprint');

    // An approval changes no count and no time. Without it in the fingerprint,
    // one manager's sign-off would be invisible on another's screen.
    $segment->forceFill(['manager_approval' => true])->save();

    expect(livePoll()->json('fingerprint'))->not->toBe($before);
});

it('moves the fingerprint when a punch is corrected', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $segment = segmentInRange($this->from);

    $before = livePoll()->json('fingerprint');

    // Same row, same day, different hours — a time correction. count() and
    // max(id) both miss it, which is why the sum of hours is in there.
    $segment->forceFill(['hours' => (float) $segment->hours + 1.25])->save();

    expect(livePoll()->json('fingerprint'))->not->toBe($before);
});

it('ignores changes outside the range it was asked about', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $before = livePoll()->json('fingerprint');

    $existing = WorkSegment::query()->firstOrFail();
    WorkSegment::query()->create([
        'employee_id' => $existing->employee_id,
        'store_id' => DemoSeeder::STORE_ID,
        'position_id' => $existing->position_id,
        // Well outside the week being polled.
        'business_date' => '2026-09-14',
        'time_in' => now()->parse('2026-09-14 14:00:00'),
        'time_out' => now()->parse('2026-09-14 22:00:00'),
        'hours' => 8,
    ]);

    expect(livePoll()->json('fingerprint'))->toBe($before);
});

// ── failure is reported, never thrown ───────────────────────────────────

it('answers 200 with the reason when TCP is down', function () {
    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    // A polling loop that receives a 5xx is a polling loop that stops, and the
    // tab it stops in goes on showing a grid somebody believes is current.
    $response = livePoll()->assertOk();

    expect($response->json('error'))->not->toBeNull()
        ->and($response->json('fingerprint'))->not->toBeNull();
});

it('does not hammer a vendor that is down', function () {
    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    livePoll()->assertOk();

    // The client's own retry budget (tcp.retry.attempts) makes one sync attempt
    // several HTTP calls, so the number that matters is not how many the first
    // poll cost — it is that the next four cost NOTHING.
    $afterFirst = segmentRequests();

    foreach (range(1, 4) as $ignored) {
        livePoll()->assertOk();
    }

    // A failed attempt holds the interval off exactly as a successful one does.
    // Otherwise every open tab retries a broken vendor on every tick, and a
    // vendor that is down gets the whole estate's polling as a load test.
    expect(segmentRequests())->toBe($afterFirst);
});

it('still reports the punches already in the table when TCP is down', function () {
    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    $expected = WorkSegment::query()
        ->where('store_id', DemoSeeder::STORE_ID)
        ->whereBetween('business_date', [$this->from, $this->to])
        ->count();

    // The hours in the table are ours and are not a convenience. Only the
    // freshness of them is in doubt.
    expect(livePoll()->json('punches'))->toBe($expected);
});

// ── the endpoint's edges ────────────────────────────────────────────────

it('refuses a range that runs backwards', function () {
    livePoll(['from' => '2026-08-17', 'to' => '2026-08-11'])->assertStatus(422);
});

it('refuses a store that does not exist', function () {
    livePoll(['store' => 987654321])->assertStatus(422);
});

it('sits behind the console authentication like every other board route', function () {
    // Asserted on the ROUTE rather than by making an anonymous request: signIn()
    // binds a stub introspector and a bearer header for the whole test, and
    // unpicking that here would be testing the harness. What matters is that
    // adding this route did not accidentally add a public one — the heartbeat
    // reads a store's worked hours.
    $route = collect(Route::getRoutes())
        ->first(fn ($candidate) => $candidate->getName() === 'board.live');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('auth.service');
});

// ── the boards no longer carry a button ─────────────────────────────────

it('shows a live status card and no pull button on the week board', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $response = test()->get(route('board.week', [
        'store' => DemoSeeder::STORE_ID, 'week' => $this->from, 'view' => 'actual',
    ]))->assertOk();

    $response->assertSee('live-card', false)
        ->assertSee(route('board.live'), false)
        ->assertDontSee("Pull the week's actual hours", false);
});

it('shows a live status card and no pull button on the day board', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $response = test()->get(route('board', ['store' => DemoSeeder::STORE_ID]))->assertOk();

    $response->assertSee('live-card', false)
        ->assertDontSee('Pull actual hours from TCP', false);
});

it('gives the boards an opening reading without calling the vendor for it', function () {
    // snapshot(), not poll(): the page must paint from our own table. A vendor
    // call in the render path is the thing the poll exists to keep out of it.
    Http::preventStrayRequests();

    $snapshot = app(LiveSegmentFeed::class)->snapshot(DemoSeeder::STORE_ID, $this->from, $this->to);

    expect($snapshot['fingerprint'])->toBeString()
        ->and($snapshot['checked_seconds_ago'])->toBeNull()
        ->and(segmentRequests())->toBe(0);
});
