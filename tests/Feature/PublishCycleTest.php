<?php

use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Models\Shift;
use App\Models\WorkSegment;
use App\Services\Scheduling\SchedulePublisher;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Publish, unpublish, re-publish
|--------------------------------------------------------------------------
|
| "The whole scheduling will be handled on our platform until the user hit
| publish." So: nothing reaches Humanity until the button is pressed, a first
| publish is a POST, and a change to something already live is a PUT over the
| SAME shift — never a second POST that would leave an employee with two.
|
| The gate that makes the PUT possible is unpublish keeping humanity_shift_id.
|
*/

beforeEach(function () {
    // These tests exercise the OAuth path deliberately, which is the default
    // mode and the one with the 401-refresh behaviour. The credentials have to
    // be present or the client dies fetching a token before it ever reaches
    // /shifts. (A static token is the other option — see humanity.auth_mode —
    // but it would skip the token call these tests assert on.)
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

    // Every employee needs a Humanity id or the publisher refuses to create an
    // unstaffed shift — the known gap it is designed to fail loudly on.
    foreach (Shift::whereNotNull('employee_id')->pluck('employee_id')->unique() as $id) {
        IntegrationIdentity::create([
            'entity_type' => 'employee',
            'entity_id' => $id,
            'system' => 'humanity',
            'external_id' => 'HE-'.$id,
            'sync_state' => 'synced',
        ]);
    }
});

/**
 * Humanity replies with a token, then an id for a create or an echo for an
 * update. The token pattern is registered FIRST: stubs match in order and the
 * first match wins, so a catch-all in front would answer the token request with
 * a shift body and the client would find no access_token.
 */
function fakeHumanity(?string $fixedId = null): void
{
    $n = 0;

    // Also resets the recorded set, so each phase of a test asserts on its own
    // requests rather than everything since the first publish.
    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*' => function () use (&$n, $fixedId) {
            // Every create must get a DISTINCT id. humanity_shift_id is UNIQUE,
            // so handing the same one back twice makes every shift after the
            // first fail on the index — which looks like a publisher bug and is
            // really just a lazy fixture.
            $n++;
            $id = $fixedId ?? 'HS-'.(900 + $n);

            return Http::response(['id' => $id, 'data' => ['id' => $id]], 200);
        },
    ]);
}

/**
 * Did a request hit exactly this Humanity path?
 *
 * The auth transport is a `_token` QUERY PARAM, so every url carries
 * `?_token=...` and a str_ends_with on the path never matches. Compare the
 * parsed path instead.
 */
function hitPath(string $method, string $path): Closure
{
    return fn ($r): bool => $r->method() === $method
        && parse_url($r->url(), PHP_URL_PATH) === $path;
}

const SHIFTS_PATH = '/api/v2/shifts';

const WORKSEGMENTS_PATH = '/v1/worksegments';

// ── the two directions never cross ──────────────────────────────────────

it('publishing sends planned shifts to Humanity and never touches TCP worksegments', function () {
    fakeHumanity();

    // The seeded day has both planned shifts AND worked hours against them.
    expect(WorkSegment::count())->toBeGreaterThan(0);

    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    Http::assertSent(hitPath('POST', SHIFTS_PATH));

    // Publishing is about the PLAN. Worked hours are TCP's record of what
    // happened and have no business being pushed anywhere as a schedule.
    Http::assertNotSent(fn ($r) => str_contains((string) parse_url($r->url(), PHP_URL_PATH), 'worksegment'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'tcplusondemand'));
});

it('publishing never changes a work segment row either', function () {
    fakeHumanity();

    $before = WorkSegment::orderBy('id')->get()
        ->map(fn (WorkSegment $w) => [$w->id, $w->time_in?->toIso8601String(), $w->time_out?->toIso8601String(),
            (bool) $w->manager_approval, $w->tcp_sync_state?->value])
        ->all();

    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    $after = WorkSegment::orderBy('id')->get()
        ->map(fn (WorkSegment $w) => [$w->id, $w->time_in?->toIso8601String(), $w->time_out?->toIso8601String(),
            (bool) $w->manager_approval, $w->tcp_sync_state?->value])
        ->all();

    expect($after)->toBe($before);
});

it('pulls actual hours from TCP with a GET, and sends nothing to Humanity', function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    // The sync filters GET /worksegments by TCP employee id. With none set it
    // correctly refuses to run rather than widening a one-store pull into every
    // store's punches — so the fixture has to supply them.
    Employee::query()->update(['tcp_employee_id' => null]);
    foreach (Employee::all() as $i => $employee) {
        $employee->forceFill(['tcp_employee_id' => 'TCP-'.$employee->id])->save();
    }

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->post('/board/pull-segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
    ])->assertRedirect();

    // GET, per the document: "GET action request to .../v1/worksegments".
    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_contains((string) parse_url($r->url(), PHP_URL_PATH), 'worksegments'));

    // And nothing at all to Humanity: the plan is not derived from the punches.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'humanity.com'));
});

it('does not create or update a planned shift when actual hours are pulled', function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $before = Shift::orderBy('id')->get()
        ->map(fn (Shift $s) => [$s->id, $s->publish_state->value, $s->humanity_shift_id, $s->payload_fingerprint])
        ->all();

    $this->post('/board/pull-segments', [
        'store_id' => DemoSeeder::STORE_ID, 'date' => $this->today,
    ])->assertRedirect();

    expect(Shift::orderBy('id')->get()
        ->map(fn (Shift $s) => [$s->id, $s->publish_state->value, $s->humanity_shift_id, $s->payload_fingerprint])
        ->all())->toBe($before);
});

it('offers both directions on the board, labelled for the right system', function () {
    $response = $this->get('/board?store='.DemoSeeder::STORE_ID)->assertOk();

    $response->assertSee('Publish this day')          // out, to Humanity
        ->assertSee('Pull actual hours from TCP')     // in, from TCP
        ->assertSee('GET /worksegments', false);
});

// ── nothing leaves until publish ────────────────────────────────────────

it('sends nothing to Humanity when a shift is merely created', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $this->post('/board/shifts', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
        'employee_id' => Shift::whereNotNull('employee_id')->value('employee_id'),
        'start' => '09:00',
        'end' => '12:00',
    ])->assertRedirect();

    Http::assertNothingSent();
});

// ── the first publish is a POST ─────────────────────────────────────────

it('publishes the visible range as POSTs and marks them published', function () {
    fakeHumanity();

    $draft = Shift::where('publish_state', 'draft')->whereNotNull('employee_id')->firstOrFail();

    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID,
        'from' => $this->today,
        'to' => $this->today,
    ])->assertRedirect();

    Http::assertSent(hitPath('POST', SHIFTS_PATH));

    expect($draft->fresh()->publish_state->value)->toBe('published')
        ->and($draft->fresh()->humanity_shift_id)->not->toBeNull()
        ->and(session('ok'))->toContain('created');
});

it('is idempotent — publishing twice sends nothing the second time', function () {
    fakeHumanity();

    $payload = ['store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today];
    $this->post('/board/publish', $payload)->assertRedirect();

    // A fingerprint that still matches means unchanged, and unchanged costs no
    // request. Pressing the button twice must not double anybody's schedule.
    fakeHumanity();
    $this->post('/board/publish', $payload)->assertRedirect();

    Http::assertNotSent(hitPath('POST', SHIFTS_PATH));
    expect(session('ok'))->toContain('Nothing to publish');
});

// ── edit needs unpublish, and then it is a PUT ──────────────────────────

it('sends a PUT over the same Humanity shift after unpublish and edit', function () {
    fakeHumanity();

    $shift = Shift::where('publish_state', 'draft')->whereNotNull('employee_id')->firstOrFail();
    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    $humanityId = $shift->fresh()->humanity_shift_id;
    expect($humanityId)->not->toBeNull();

    // Unpublish, edit, re-publish.
    $this->post("/board/shifts/{$shift->id}/unpublish")->assertRedirect();
    $this->put("/board/shifts/{$shift->id}", [
        'date' => $this->today,
        'employee_id' => $shift->employee_id,
        'start' => '15:30',
        'end' => '19:30',
    ])->assertRedirect();

    fakeHumanity($humanityId);
    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    // A PUT at the SAME id. A second POST would leave the employee holding two
    // shifts for one block of work.
    Http::assertSent(hitPath('PUT', SHIFTS_PATH.'/'.$humanityId));
    Http::assertNotSent(hitPath('POST', SHIFTS_PATH));

    expect($shift->fresh()->publish_state->value)->toBe('published')
        ->and($shift->fresh()->humanity_shift_id)->toBe($humanityId);
});

it('leaves the shift in Humanity when it is unpublished', function () {
    fakeHumanity();

    $shift = Shift::where('publish_state', 'draft')->whereNotNull('employee_id')->firstOrFail();
    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    fakeHumanity();
    $this->post("/board/shifts/{$shift->id}/unpublish")->assertRedirect();

    // Unpublish is a LOCAL unlock. Employees keep seeing the last published
    // version rather than watching a shift vanish mid-edit.
    Http::assertNotSent(hitPath('PUT', SHIFTS_PATH.'/'.$shift->fresh()->humanity_shift_id));
    Http::assertNotSent(hitPath('POST', SHIFTS_PATH));
    expect($shift->fresh()->publish_state->value)->toBe('unlocked')
        ->and($shift->fresh()->humanity_shift_id)->not->toBeNull();
});

it('reports unchanged rather than re-sending when nothing was edited after unpublish', function () {
    fakeHumanity();

    $shift = Shift::where('publish_state', 'draft')->whereNotNull('employee_id')->firstOrFail();
    $payload = ['store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today];
    $this->post('/board/publish', $payload)->assertRedirect();
    $this->post("/board/shifts/{$shift->id}/unpublish")->assertRedirect();

    fakeHumanity();
    $this->post('/board/publish', $payload)->assertRedirect();

    // Unlocking is not itself a change: the fingerprint was kept, so a manager
    // who thought better of it costs Humanity nothing.
    Http::assertNotSent(hitPath('POST', SHIFTS_PATH));
    Http::assertNotSent(hitPath('PUT', SHIFTS_PATH.'/'.$shift->fresh()->humanity_shift_id));
    // ...and it settles back to published without a request.
    expect($shift->fresh()->publish_state->value)->toBe('published')
        ->and(session('ok'))->toContain('unchanged');
});

it('refuses to unpublish something that was never published', function () {
    $draft = Shift::where('publish_state', 'draft')->firstOrFail();

    $this->post("/board/shifts/{$draft->id}/unpublish")->assertRedirect();

    expect(session('err'))->toContain('nothing to unpublish')
        ->and($draft->fresh()->publish_state->value)->toBe('draft');
});

// ── the API surface agrees with the board ───────────────────────────────

it('publishes over the API too', function () {
    fakeHumanity();

    $this->postJson('/api/shifts/publish', [
        'store' => DemoSeeder::STORE_ID,
        'from' => $this->today,
        'to' => $this->today,
    ])->assertOk()->assertJsonPath('data.store_id', DemoSeeder::STORE_ID);

    Http::assertSent(hitPath('POST', SHIFTS_PATH));
});

it('unpublishes over the API too', function () {
    fakeHumanity();

    $shift = Shift::where('publish_state', 'draft')->whereNotNull('employee_id')->firstOrFail();
    $this->post('/board/publish', [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $this->today, 'to' => $this->today,
    ])->assertRedirect();

    $this->postJson("/api/shifts/{$shift->id}/unpublish")->assertOk();

    expect($shift->fresh()->publish_state->value)->toBe('unlocked');
});

it('approves a single segment over the API, which had no way to at all', function () {
    $segment = WorkSegment::whereNotNull('time_out')
        ->where('manager_approval', false)->firstOrFail();

    $this->postJson("/api/work-segments/{$segment->id}/approve")->assertOk();

    expect((bool) $segment->fresh()->manager_approval)->toBeTrue();
});
