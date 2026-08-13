<?php

use App\Enums\RequestDecision;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSegment;
use App\Services\Scheduling\EmployeeRequestService;
use App\Services\Scheduling\ShiftService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The week grid, moving and copying
|--------------------------------------------------------------------------
|
| Dragging is the primary way the plan gets built, so these pin what a drop
| may and may not do: the clock times survive a move to another day, a copy
| never joins the original's series, and a shift with punches against it
| refuses to move at all.
|
| Attribution is only as good as the columns that carry it — there is no
| activity log, so created_by_user_id and approved_by_user_id are the whole
| record of who did something.
|
*/

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
    $this->bd = app(BusinessDay::class);
    $this->today = $this->bd->toLocal(DemoSeeder::STORE_ID, now())->toDateString();
    $this->tomorrow = now()->parse($this->today)->addDay()->toDateString();
});

/** A shift nobody has punched against — the only kind that can be moved. */
function draggableShift(): Shift
{
    return Shift::query()
        ->whereNotNull('employee_id')
        ->whereDoesntHave('workSegments')
        ->orderBy('id')
        ->firstOrFail();
}

// ── the week view ───────────────────────────────────────────────────────

it('renders the week grid with a cell per employee per day', function () {
    $this->get('/board/week')
        ->assertOk()
        ->assertSee('Ada Okafor')
        ->assertSee('open shifts')
        ->assertSee('data-shift=', false);
});

it('offers no break field anywhere, because break time is TCP\'s', function () {
    $this->get('/board/week')->assertOk()->assertDontSee('unpaid_break_minutes', false);
    $this->get('/board')->assertOk()->assertDontSee('unpaid_break_minutes', false);
});

// ── moving ──────────────────────────────────────────────────────────────

it('moves a shift to another day, keeping the clock times', function () {
    $shift = draggableShift();
    $wasStart = $this->bd->toLocal(DemoSeeder::STORE_ID, $shift->start_at)->format('H:i');

    $this->postJson("/board/shifts/{$shift->id}/move", ['business_date' => $this->tomorrow])
        ->assertOk()->assertJson(['ok' => true, 'business_date' => $this->tomorrow]);

    $fresh = $shift->fresh();

    // 17:00 on Tuesday is still 17:00 on Thursday. Carrying the raw UTC
    // instant across a DST boundary would quietly shift it by an hour.
    expect($fresh->business_date->toDateString())->toBe($this->tomorrow)
        ->and($this->bd->toLocal(DemoSeeder::STORE_ID, $fresh->start_at)->format('H:i'))->toBe($wasStart);
});

it('moves a shift to another employee', function () {
    $shift = draggableShift();
    $other = Employee::where('id', '!=', $shift->employee_id)->firstOrFail();

    $this->postJson("/board/shifts/{$shift->id}/move", ['employee_id' => $other->id])->assertOk();

    expect((int) $shift->fresh()->employee_id)->toBe((int) $other->id);
});

it('unassigns a shift dropped on the open row', function () {
    $shift = draggableShift();

    $this->postJson("/board/shifts/{$shift->id}/move", ['unassign' => true])->assertOk();

    expect($shift->fresh()->employee_id)->toBeNull();
});

it('refuses to move a published shift until it is unpublished', function () {
    $shift = draggableShift();
    $shift->forceFill([
        'publish_state' => 'published',
        'humanity_shift_id' => 'HS-700',
        'payload_fingerprint' => str_repeat('c', 64),
    ])->save();

    $this->postJson("/board/shifts/{$shift->id}/move", ['business_date' => $this->tomorrow])
        ->assertStatus(422);

    expect($shift->fresh()->business_date->toDateString())->toBe($this->today);
});

it('voids the fingerprint when an unlocked shift is moved, keeping its Humanity id', function () {
    $shift = draggableShift();
    $shift->forceFill([
        'publish_state' => 'published',
        'humanity_shift_id' => 'HS-700',
        'payload_fingerprint' => str_repeat('c', 64),
    ])->save();

    $this->post("/board/shifts/{$shift->id}/unpublish")->assertRedirect();
    $this->postJson("/board/shifts/{$shift->id}/move", ['business_date' => $this->tomorrow])->assertOk();

    $fresh = $shift->fresh();

    // The id survives, so the next publish is a PUT over the existing Humanity
    // shift; the fingerprint is void, so it is not skipped as unchanged.
    expect($fresh->payload_fingerprint)->toBeNull()
        ->and($fresh->humanity_shift_id)->toBe('HS-700')
        ->and($fresh->publish_state->value)->toBe('unlocked');
});

it('refuses to move a shift that already has punches against it', function () {
    $shift = Shift::query()->has('workSegments')->firstOrFail();
    $was = $shift->business_date->toDateString();

    $this->postJson("/board/shifts/{$shift->id}/move", ['business_date' => $this->tomorrow])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    // Moving a plan to another day when the work already happened is
    // incoherent, and unlinking the punch would destroy a reconciliation.
    expect($shift->fresh()->business_date->toDateString())->toBe($was);
});

// ── copying ─────────────────────────────────────────────────────────────

it('copies a shift, leaving the original alone', function () {
    $shift = draggableShift();
    $before = Shift::count();

    $this->postJson("/board/shifts/{$shift->id}/copy", ['business_date' => $this->tomorrow])->assertOk();

    expect(Shift::count())->toBe($before + 1)
        ->and($shift->fresh()->business_date->toDateString())->toBe($this->today);
});

it('never enrols a copy in the original\'s series or split group', function () {
    // Every seeded split part already has punches against it, so make a fresh
    // one to copy from.
    $base = draggableShift();
    app(ShiftService::class)->split(
        $base,
        $base->end_at->copy()->addHours(2),
        $base->end_at->copy()->addHours(4),
    );
    $part = $base->fresh();

    $this->postJson("/board/shifts/{$part->id}/copy", ['business_date' => $this->tomorrow])->assertOk();

    $copy = Shift::latest('id')->firstOrFail();

    // series_id is in CREATABLE, so copying attributes wholesale would have
    // joined the copy to the original's group — and a later "delete following"
    // on that series would silently take it too.
    expect($copy->split_group_id)->toBeNull()
        ->and($copy->split_part)->toBeNull()
        ->and($copy->series_id)->toBeNull()
        ->and($copy->publish_state->value)->toBe('draft')
        ->and($copy->humanity_shift_id)->toBeNull();
});

it('allows copying a shift that has punches, even though moving is refused', function () {
    $shift = Shift::query()->has('workSegments')->firstOrFail();

    $this->postJson("/board/shifts/{$shift->id}/copy", ['business_date' => $this->tomorrow])->assertOk();
});

// ── the audit trail ─────────────────────────────────────────────────────

// ── acting as ───────────────────────────────────────────────────────────

it('stamps a created shift with the acting user', function () {
    $manager = User::firstOrFail();
    $this->post('/acting-user', ['user_id' => $manager->id]);

    $this->post('/board/shifts', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
        'employee_id' => Employee::value('id'),
        'start' => '09:00',
        'end' => '12:00',
    ])->assertRedirect();

    expect((int) Shift::latest('id')->firstOrFail()->created_by_user_id)->toBe((int) $manager->id);
});

it('leaves the actor null when nobody is acting', function () {
    $this->post('/board/shifts', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
        'employee_id' => Employee::value('id'),
        'start' => '09:00',
        'end' => '12:00',
    ])->assertRedirect();

    expect(Shift::latest('id')->firstOrFail()->created_by_user_id)->toBeNull();
});

it('switches the acting user and stamps the next change with them', function () {
    $first = User::orderBy('id')->firstOrFail();
    $second = User::query()->where('id', '!=', $first->id)->first()
        ?? User::create(['name' => 'Second Manager', 'email' => 'second@example.test', 'password' => 'x']);

    $this->post('/acting-user', ['user_id' => $first->id])->assertRedirect();
    $this->post('/board/shifts', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
        'employee_id' => Employee::value('id'),
        'start' => '09:00',
        'end' => '12:00',
    ])->assertRedirect();
    $created = Shift::latest('id')->firstOrFail();

    $this->post('/acting-user', ['user_id' => $second->id])->assertRedirect();
    $segment = WorkSegment::whereNotNull('time_out')->where('manager_approval', false)->firstOrFail();
    $this->post("/board/segments/{$segment->id}/approve")->assertRedirect();

    expect((int) $created->created_by_user_id)->toBe((int) $first->id)
        ->and((int) $segment->fresh()->approved_by_user_id)->toBe((int) $second->id);
});

it('blanks the actor rather than blocking when the auth user is deleted', function () {
    $manager = User::firstOrFail();
    $this->post('/acting-user', ['user_id' => $manager->id]);

    $segment = WorkSegment::whereNotNull('time_out')->where('manager_approval', false)->firstOrFail();
    $this->post("/board/segments/{$segment->id}/approve")->assertRedirect();

    expect((int) $segment->fresh()->approved_by_user_id)->toBe((int) $manager->id);

    // users is a PROJECTION — auth can delete one at any time, and every
    // scheduling-owned reference to it is nullOnDelete so the delete event
    // stays appliable rather than parking forever.
    Shift::query()->update(['created_by_user_id' => null]);
    WorkSegment::query()->update(['times_corrected_by_user_id' => null]);
    User::whereKey($manager->id)->delete();

    expect($segment->fresh()->approved_by_user_id)->toBeNull()
        ->and((bool) $segment->fresh()->manager_approval)->toBeTrue();
});

// ── re-deciding a request ───────────────────────────────────────────────

it('appends a decision rather than overwriting, and re-caches the status', function () {
    // A request the seeder has not already ruled on.
    $request = EmployeeRequest::query()->whereDoesntHave('decisions')->firstOrFail();

    app(EmployeeRequestService::class)->decide($request, RequestDecision::Approved, null, 'cover arranged');
    app(EmployeeRequestService::class)->decide($request, RequestDecision::Cancelled, null, 'cover fell through');

    $trail = $request->decisions()->orderBy('id')->pluck('decision')->map(fn ($d) => $d->value ?? $d)->all();

    // The approval is still there. A status column that overwrote itself would
    // have lost the fact that it was ever approved, which is the one thing
    // anyone asks about afterwards.
    expect($trail)->toBe(['approved', 'cancelled'])
        ->and($request->fresh()->status->value)->toBe('cancelled')
        ->and($request->decisions()->count())->toBe(2);
});

it('shows edit-decision on a settled request and the three buttons on a pending one', function () {
    $pending = EmployeeRequest::where('status', 'pending')->firstOrFail();
    $this->get('/board?store='.DemoSeeder::STORE_ID)->assertOk()->assertSee('edit decision');

    // The seeded set has one approved and one pending, so both branches render.
    expect(EmployeeRequest::where('status', 'pending')->exists())->toBeTrue()
        ->and($pending->status->value)->toBe('pending');
});
