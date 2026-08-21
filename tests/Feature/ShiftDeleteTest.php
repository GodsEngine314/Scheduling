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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Deleting a planned shift
|--------------------------------------------------------------------------
|
| THE SYMMETRIC RULE: anything that changes a shift Humanity is holding — edit,
| move or cancel — goes unpublish, change, republish. Delete used to be the one
| ungated path; it is not any more, so every test here that deletes a published
| shift unpublishes it first, which is the flow a manager now follows.
|
| THE BUG THIS FILE EXISTS FOR: nothing called SchedulePublisher::withdraw().
| ShiftService::delete is local only — its docblock says nothing in the class
| talks to Humanity — so deleting a published shift took it off the board and
| left it live on the employee's roster. Worse, the row holding its
| humanity_shift_id was soft-deleted with it, so no later run could ever have
| found it to clean up. Somebody turns up for a shift cancelled a week earlier.
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

/**
 * ONE fake per test, and it has to be one.
 *
 * Http::fake() APPENDS stub callbacks and the FIRST match wins, so calling it a
 * second time to change a '*' catch-all does nothing — the original keeps
 * answering. Both of this file's first drafts were wrong that way: the 422 never
 * arrived and the delete looked like it had been allowed.
 *
 * Every create also needs a DISTINCT id, because humanity_shift_id is UNIQUE and
 * handing the same one back twice fails on the index rather than in the code
 * under test.
 */
function fakeDeleteWire(int $deleteStatus = 200): void
{
    $n = 0;

    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*' => function ($request) use (&$n, $deleteStatus) {
            if ($request->method() === 'DELETE') {
                return Http::response(['status' => $deleteStatus === 200 ? 1 : 0], $deleteStatus);
            }

            $n++;

            return Http::response(['status' => 1, 'data' => ['id' => 'HS-'.(500 + $n)]], 200);
        },
    ]);
}

/** A draft nobody has punched against. */
function deletableShift(): Shift
{
    return Shift::query()
        ->where('publish_state', 'draft')
        ->whereNotNull('employee_id')
        ->whereNotNull('position_id')
        ->whereDoesntHave('workSegments')
        ->orderBy('id')
        ->firstOrFail();
}

// ── the bug ────────────────────────────────────────────────────────────

it('withdraws a published shift from Humanity before deleting it locally', function () {
    fakeDeleteWire();

    $shift = deletableShift();
    $this->publisher->push($shift);

    $humanityId = $shift->fresh()->humanity_shift_id;
    expect($humanityId)->not->toBeNull();

    // Unpublish first — the shift is locked until it is. Local only: it keeps
    // its humanity_shift_id, which is what the withdraw below needs.
    unpublishViaBoard($shift)->assertRedirect();

    $this->delete("/board/shifts/{$shift->id}", ['rule' => 'following'])->assertRedirect();

    // The vendor was told. Without this the shift stays on somebody's roster
    // forever, because the row that knew its id is gone.
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/shifts/'.$humanityId);

    expect(Shift::query()->whereKey($shift->id)->exists())->toBeFalse()
        ->and(Shift::withTrashed()->find($shift->id)->trashed())->toBeTrue()
        ->and(session('ok'))->toContain('withdrawn from Humanity');
});

it('refuses the local delete when Humanity will not take the shift back', function () {
    // 422, not 500: a 4xx returns immediately instead of spending the retry
    // budget on three attempts and a real backoff. Registered up front, because
    // a later fake() cannot displace an earlier catch-all.
    fakeDeleteWire(deleteStatus: 422);

    $shift = deletableShift();
    $this->publisher->push($shift);
    unpublishViaBoard($shift)->assertRedirect();

    $this->delete("/board/shifts/{$shift->id}")->assertRedirect();

    // STILL THERE, on purpose. Deleting locally anyway would lose the only
    // record of the id, and with it any chance of ever withdrawing it. A shift
    // the manager can try to cancel again is the recoverable failure.
    expect(Shift::query()->whereKey($shift->id)->exists())->toBeTrue()
        ->and($shift->fresh()->humanity_shift_id)->not->toBeNull()
        ->and(session('err'))->not->toBeNull();
});

it('sends nothing to Humanity when the shift was never published', function () {
    fakeDeleteWire();

    $draft = deletableShift();

    $this->delete("/board/shifts/{$draft->id}")->assertRedirect();

    Http::assertNothingSent();

    expect(Shift::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(session('ok'))->toContain('Humanity was not holding it');
});

it('withdraws a shift that failed mid-publish, which still keeps its Humanity id', function () {
    fakeDeleteWire();

    $shift = deletableShift();
    $this->publisher->push($shift);
    $humanityId = $shift->fresh()->humanity_shift_id;

    // The state a half-finished publish leaves behind: 'failed', and still held
    // by Humanity. Filtering on publish_state->isLive() would skip exactly this
    // row — the one whose delete had already been tried once.
    $shift->forceFill(['publish_state' => 'failed'])->save();

    $this->delete("/board/shifts/{$shift->id}")->assertRedirect();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/shifts/'.$humanityId);
});

it('withdraws every published occurrence of a series, not just the one clicked', function () {
    fakeDeleteWire();

    $series = (string) Str::ulid();
    $first = deletableShift();

    // A second occurrence, later in the same series.
    $second = $first->replicate();
    $second->business_date = now()->parse($this->today)->addDay()->toDateString();
    $second->start_at = $first->start_at->addDay();
    $second->end_at = $first->end_at->addDay();
    $second->save();

    Shift::query()->whereIn('id', [$first->id, $second->id])->update(['series_id' => $series]);

    $this->publisher->push($first->fresh());
    $this->publisher->push($second->fresh());

    $ids = [$first->fresh()->humanity_shift_id, $second->fresh()->humanity_shift_id];
    expect($ids[0])->not->toBeNull()->and($ids[1])->not->toBeNull()->and($ids[0])->not->toBe($ids[1]);

    // Only the shift being CLICKED needs unpublishing. Its later siblings are
    // taken by the series rule and withdrawn from Humanity with it — otherwise a
    // series could never be deleted at all without unlocking every occurrence.
    unpublishViaBoard($first)->assertRedirect();

    $this->delete("/board/shifts/{$first->id}", ['rule' => 'following'])->assertRedirect();

    // BOTH. A series delete removes rows for dates the manager never looked at,
    // and each published one is its own Humanity shift with its own id.
    foreach ($ids as $id) {
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/shifts/'.$id);
    }

    expect(Shift::query()->whereIn('id', [$first->id, $second->id])->count())->toBe(0);
});

// ── the control on the board ───────────────────────────────────────────

it('offers a delete control on every planned chip in the week view', function () {
    $response = $this->get('/board/week?store='.DemoSeeder::STORE_ID.'&tab=planned')->assertOk();

    $shift = Shift::query()->where('store_id', DemoSeeder::STORE_ID)->orderBy('id')->firstOrFail();

    $response->assertSee('Delete shift #'.$shift->id, false)
        ->assertSee(route('board.shifts.destroy', $shift), false)
        // The rule is stated in the form rather than left to the controller's
        // default, so the confirm and the server cannot drift apart.
        ->assertSee('name="rule" value="following"', false);
});

it('offers no button at all on a published chip, and points at the range control', function () {
    fakeDeleteWire();

    $shift = deletableShift();
    $this->publisher->push($shift);

    $response = $this->get('/board/week?store='.DemoSeeder::STORE_ID.'&tab=planned')->assertOk();

    /*
     * THE RULE IS UNCHANGED — no delete is offered until the shift is unpublished
     * — but the unlock moved off the chip. It served "unlock the week, change it,
     * republish", and one padlock per chip made that fourteen clicks.
     *
     * A locked chip therefore carries NO button. What it must not be is a silent
     * dead end, so its tooltip names where the unlock went.
     */
    $response->assertDontSee(route('board.shifts.destroy', $shift).'" class="chip-del-form', false)
        ->assertSee('Unpublish all', false)
        ->assertSee('use &quot;Unpublish all&quot; above to unlock the week', false);
});

it('acts on the whole week from one press, not one shift', function () {
    fakeDeleteWire();

    // Three published shifts ON ONE DAY, which used to be three padlocks.
    // Built rather than found: deletableShift() takes the first DRAFT row, and
    // publishing each one exhausts the seeded pool before the third.
    $template = deletableShift();

    $shifts = collect(range(0, 2))->map(function (int $i) use ($template): Shift {
        $shift = Shift::query()->create([
            'store_id' => $template->store_id,
            'employee_id' => $template->employee_id,
            'position_id' => $template->position_id,
            'business_date' => $template->business_date,
            // Staggered so three rows are three distinct shifts rather than one
            // shift asserted three times.
            'start_at' => $template->start_at->copy()->addHours($i * 2),
            'end_at' => $template->end_at->copy()->addHours($i * 2),
        ]);

        $this->publisher->push($shift);

        return $shift->fresh();
    });

    expect($shifts->every(fn ($s) => $s->publish_state->value === 'published'))->toBeTrue();

    unpublishViaBoard($shifts->first())->assertRedirect();

    expect(session('ok'))->toContain('3 shifts unpublished');

    foreach ($shifts as $shift) {
        expect($shift->fresh()->publish_state->value)->toBe('unlocked')
            // Local only: Humanity keeps every one of them.
            ->and($shift->fresh()->humanity_shift_id)->not->toBeNull();
    }
});

it('warns in the confirm text when a delete reaches Humanity or a whole series', function () {
    fakeDeleteWire();

    $shift = deletableShift();
    $this->publisher->push($shift);
    // Unlocked, so the chip offers the delete again — and the confirm has to say
    // that Humanity is still holding it.
    $this->publisher->unpublish($shift->fresh());
    $shift->forceFill(['series_id' => (string) Str::ulid()])->save();

    $this->get('/board/week?store='.DemoSeeder::STORE_ID.'&tab=planned')
        ->assertOk()
        ->assertSee('Humanity is holding this shift', false)
        ->assertSee('every later one go with it', false);
});

it('refuses to delete a published shift, and says to unpublish it first', function () {
    fakeDeleteWire();

    $shift = deletableShift();
    $this->publisher->push($shift);

    $this->delete("/board/shifts/{$shift->id}")->assertRedirect();

    // Nothing local, and nothing at the vendor. Unpublish is free and
    // reversible; a DELETE that has already gone is not.
    expect(Shift::query()->whereKey($shift->id)->exists())->toBeTrue()
        ->and(session('err'))->toContain('cannot be deleted')
        ->and(session('err'))->toContain('Unpublish it first');

    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
});

// ── the credential message ─────────────────────────────────────────────

it('says the credentials were rejected rather than naming an HTTP status on a URL', function () {
    Http::fake([
        '*oauth2/token*' => Http::response(['error' => 'invalid_grant'], 401),
    ]);

    $result = $this->publisher->publishShift(deletableShift());

    // This string lands on shifts.last_publish_error and is what a manager reads
    // when the board comes back red. "HTTP 401 from .../token.php" is true and
    // tells them nothing about whose problem it is.
    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toContain('rejected the credentials')
        ->and($result['error'])->toContain('NOTHING was sent')
        ->and($result['error'])->toContain('SSO');
});

// ── unpublish, change, republish ───────────────────────────────────────

it('sends nothing to Humanity when a shift is unpublished, then a PUT when republished', function () {
    fakeDeleteWire();

    $shift = deletableShift();
    $this->publisher->push($shift);
    $humanityId = $shift->fresh()->humanity_shift_id;

    $before = count(Http::recorded());

    // STEP 1 — unpublish. Local only. Employees keep seeing the shift exactly as
    // it is, which is why this is free to press and free to change your mind on.
    unpublishViaBoard($shift)->assertRedirect();

    expect(count(Http::recorded()))->toBe($before)
        ->and($shift->fresh()->publish_state->value)->toBe('unlocked')
        // The id is KEPT. It is what makes step 3 a PUT rather than a second POST.
        ->and($shift->fresh()->humanity_shift_id)->toBe($humanityId);

    // STEP 2 — change it. Still nothing sent.
    $this->put("/board/shifts/{$shift->id}", [
        'date' => $this->today,
        'employee_id' => $shift->employee_id,
        'position_id' => $shift->position_id,
        'start' => '15:30',
        'end' => '19:30',
    ])->assertRedirect();

    expect(count(Http::recorded()))->toBe($before)
        // The edit voided the fingerprint, which is what marks it as changed.
        ->and($shift->fresh()->payload_fingerprint)->toBeNull();

    // STEP 3 — republish. The comparison happens here: a shift Humanity already
    // holds goes as a PUT over the SAME id.
    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/shifts/'.$humanityId);

    expect($shift->fresh()->publish_state->value)->toBe('published')
        ->and($shift->fresh()->humanity_shift_id)->toBe($humanityId);
});

it('reports unchanged and sends nothing when nothing was actually edited', function () {
    fakeDeleteWire();

    $shift = deletableShift();
    $this->publisher->push($shift);

    unpublishViaBoard($shift)->assertRedirect();

    $humanityId = $shift->fresh()->humanity_shift_id;

    // Unlocking is not itself a change: the fingerprint was kept, so a manager
    // who thinks better of it costs Humanity nothing at all.
    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    // Asserted per-SHIFT, not as a request count: the sweep covers the whole
    // range and the demo day has other drafts in it, which legitimately do get
    // sent. What must not happen is a request for THIS one.
    Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['PUT', 'DELETE'], true)
        && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/shifts/'.$humanityId);

    expect($shift->fresh()->publish_state->value)->toBe('published')
        ->and($shift->fresh()->humanity_shift_id)->toBe($humanityId);
});

it('calls the button Republish once Humanity is already holding something', function () {
    fakeDeleteWire();

    $shift = deletableShift();
    $this->publisher->push($shift);
    unpublishViaBoard($shift)->assertRedirect();

    // "Publish" on a shift the store can already see reads like it is about to
    // create a second one.
    $this->get('/board/week?store='.DemoSeeder::STORE_ID.'&tab=planned')
        ->assertOk()
        ->assertSee('Republish this week', false)
        ->assertSee('PUT /shifts/{id}', false);
});
