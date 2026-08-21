<?php

use App\Models\HumanitySchedule;
use App\Models\IntegrationIdentity;
use App\Models\Shift;
use App\Services\Scheduling\SchedulePublisher;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| One button, three states
|--------------------------------------------------------------------------
|
| The Humanity card is a state machine, and it cycles the way the work does:
|
|     prepare the week   ->  [Publish]     POST /shifts, the week goes live
|     want to change it  ->  [Unpublish]   local only, nothing is sent
|     changed it         ->  [Republish]   PUT over the same shifts
|
| Before this, a fully published week showed a DISABLED "Publish" button and the
| unlock lived in a different card — a dead end at exactly the point where a
| manager wants to change something.
|
| SENDING WINS whenever anything is outstanding: an unsent change means employees
| are reading a roster that does not match the plan. Only once everything in view
| is live AND unchanged does the button become the unlock.
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

    foreach (Shift::query()->whereNotNull('employee_id')->pluck('employee_id')->unique() as $id) {
        IntegrationIdentity::query()->create([
            'entity_type' => 'employee',
            'entity_id' => $id,
            'system' => 'humanity',
            'external_id' => 'HE-'.$id,
            'sync_state' => 'synced',
        ]);
    }

    $pairs = Shift::query()
        ->whereNotNull('position_id')
        ->get(['store_id', 'position_id'])
        ->unique(fn (Shift $shift): string => $shift->store_id.':'.$shift->position_id);

    foreach ($pairs as $shift) {
        HumanitySchedule::query()->create([
            'schedule_id' => "HSCH-{$shift->store_id}-{$shift->position_id}",
            'store_id' => $shift->store_id,
            'position_id' => $shift->position_id,
            'name' => 'Fixture',
        ]);
    }

    signIn();
});

function fakeCycleWire(): void
{
    $n = 0;

    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*' => function () use (&$n) {
            $n++;

            return Http::response(['status' => 1, 'data' => ['id' => 'HS-'.(700 + $n)]], 200);
        },
    ]);
}

/** The day board, which is the smallest range the card is drawn for. */
function cycleDay(): TestResponse
{
    return test()->get('/board?store='.DemoSeeder::STORE_ID.'&date='.test()->today)->assertOk();
}

/**
 * Everything in view sent, so the range is fully live and unchanged.
 *
 * A worked shift is never publishable — history is not a plan — so those are
 * settled by hand to leave the day genuinely nothing-to-send.
 */
function publishTheWholeDay(): void
{
    test()->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID,
        'from' => test()->today,
        'to' => test()->today,
    ])->assertRedirect();

    Shift::query()
        ->forStoreBetween(DemoSeeder::STORE_ID, test()->today, test()->today)
        ->whereIn('publish_state', ['draft', 'failed'])
        ->get()
        ->each(fn (Shift $shift) => $shift->forceFill([
            'publish_state' => 'published',
            'humanity_shift_id' => 'SETTLED-'.$shift->id,
            'payload_fingerprint' => str_repeat('f', 64),
            'published_at' => now(),
        ])->save());
}

// ── state 1: publish ───────────────────────────────────────────────────

it('offers Publish while the week has never been sent', function () {
    cycleDay()
        ->assertSee('Publish this day', false)
        ->assertDontSee('Republish this day', false)
        ->assertDontSee('Unpublish this day', false)
        // Nothing is live, so there is nothing to unlock and no route offered.
        ->assertDontSee(route('board.shifts.unpublish-all'), false);
});

it('says the shifts are all new, so they go as POSTs', function () {
    cycleDay()->assertSee('All new, so they go as', false)
        ->assertSee('POST /shifts', false);
});

// ── state 2: unpublish ─────────────────────────────────────────────────

it('turns into Unpublish once everything in view is live and unchanged', function () {
    fakeCycleWire();
    publishTheWholeDay();

    // THE DEAD END THIS FIXES: nothing is outstanding, so the old card showed a
    // disabled "Publish" and offered no way to change anything.
    cycleDay()
        ->assertSee('Unpublish this day', false)
        ->assertSee(route('board.shifts.unpublish-all'), false)
        ->assertDontSee('Publish this day', false)
        ->assertDontSee('Republish this day', false);
});

it('promises that unpublishing contacts nobody', function () {
    fakeCycleWire();
    publishTheWholeDay();

    // "Unpublish" reads like it withdraws the shift. It does not, and the card
    // has to say so where the button is.
    cycleDay()
        ->assertSee('Humanity is not', false)
        ->assertSee('until you republish', false);
});

it('sends nothing when the unpublish button is pressed', function () {
    fakeCycleWire();
    publishTheWholeDay();

    $before = count(Http::recorded());

    $this->post(route('board.shifts.unpublish-all'), [
        'store_id' => DemoSeeder::STORE_ID,
        'from' => $this->today,
        'to' => $this->today,
    ])->assertRedirect();

    expect(count(Http::recorded()))->toBe($before)
        ->and(session('ok'))->toContain('still live in Humanity');
});

// ── state 3: republish ─────────────────────────────────────────────────

it('turns into Republish after unpublishing, because Humanity already holds it', function () {
    fakeCycleWire();
    publishTheWholeDay();

    $this->post(route('board.shifts.unpublish-all'), [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    cycleDay()
        ->assertSee('Republish this day', false)
        ->assertDontSee('Unpublish this day', false)
        // The word matters: "Publish" on a shift the store can already see reads
        // like it is about to create a second one.
        ->assertSee('PUT /shifts/{id}', false)
        ->assertSee('never a second', false);
});

it('completes the cycle — republishing sends PUTs and the button goes back to Unpublish', function () {
    fakeCycleWire();
    publishTheWholeDay();

    // No punches against it. A worked shift is never publishable — history is
    // not a plan — so editing one would produce no request at all and this test
    // would be asserting on the wrong row.
    $live = Shift::query()
        ->forStoreBetween(DemoSeeder::STORE_ID, $this->today, $this->today)
        ->whereNotNull('humanity_shift_id')
        ->whereDoesntHave('workSegments')
        ->pluck('humanity_shift_id', 'id');

    expect($live)->not->toBeEmpty();

    $this->post(route('board.shifts.unpublish-all'), [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    // Change one of them, so there is something real to send.
    $shiftId = (int) $live->keys()->first();
    $this->put("/board/shifts/{$shiftId}", [
        'date' => $this->today,
        'employee_id' => Shift::find($shiftId)->employee_id,
        'position_id' => Shift::find($shiftId)->position_id,
        'start' => '15:30',
        'end' => '19:30',
    ])->assertRedirect();

    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    // A PUT over the SAME shift, never a second POST.
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/shifts/'.$live[$shiftId]);

    // ...and the button is back to the top of the cycle.
    cycleDay()->assertSee('Unpublish this day', false);
});

// ── the mixed state ────────────────────────────────────────────────────

it('keeps the unlock reachable when some of the range is outstanding and some is locked', function () {
    fakeCycleWire();
    publishTheWholeDay();

    // One fresh draft alongside a fully published day: sending wins, but the
    // locked shifts must not become uneditable with no visible control.
    $this->post('/board/shifts', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
        'employee_id' => Shift::query()->whereNotNull('employee_id')->value('employee_id'),
        'position_id' => Shift::query()->whereNotNull('position_id')->value('position_id'),
        'start' => '06:00',
        'end' => '08:00',
    ])->assertRedirect();

    cycleDay()
        ->assertSee('Publish this day', false)
        ->assertSee('also unpublish', false)
        ->assertSee(route('board.shifts.unpublish-all'), false);
});
