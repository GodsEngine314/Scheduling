<?php

use App\Models\HumanitySchedule;
use App\Models\IntegrationIdentity;
use App\Models\Shift;
use App\Models\WorkSegment;
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
| The two range actions: unpublish all, delete all
|--------------------------------------------------------------------------
|
| Both replaced per-chip controls, for the same reason in each case: the workflow
| is week-sized and the buttons were shift-sized. "Unpublish, change the week,
| republish" meant clicking fourteen padlocks before a manager could touch
| anything, and clearing a week meant fourteen confirms.
|
| WHAT HAS TO BE TRUE, and what this file pins:
|
|   SCOPE. "All" is the store and the span on screen, never the table. A range
|   action that reached one row outside its own dates would be the worst kind of
|   bug here — silent, destructive, and found later.
|
|   ORDER. Humanity is told before the local row goes, and a shift whose
|   withdrawal failed is NOT deleted. The other order loses the shift off the
|   board while the vendor keeps it, with nothing left to retry from.
|
|   UNPUBLISH SENDS NOTHING. It is local: employees keep seeing every shift
|   exactly as it was, and the next publish sends PUTs over the same shifts.
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

    // Publishing needs a Humanity employee id and a schedule id per
    // store+position, or every push refuses before it reaches the wire.
    foreach (Shift::query()->whereNotNull('employee_id')->pluck('employee_id')->unique() as $id) {
        IntegrationIdentity::query()->firstOrCreate([
            'entity_type' => 'employee',
            'entity_id' => $id,
            'system' => 'humanity',
        ], [
            'external_id' => 'HE-'.$id,
            'sync_state' => 'synced',
        ]);
    }

    $pairs = Shift::query()
        ->whereNotNull('position_id')
        ->get(['store_id', 'position_id'])
        ->unique(fn (Shift $shift): string => $shift->store_id.':'.$shift->position_id);

    foreach ($pairs as $shift) {
        HumanitySchedule::query()->firstOrCreate([
            'store_id' => $shift->store_id,
            'position_id' => $shift->position_id,
        ], [
            'schedule_id' => "HSCH-{$shift->store_id}-{$shift->position_id}",
            'name' => 'Fixture',
        ]);
    }

    signIn();
});

/**
 * ONE fake per test, and it has to be one.
 *
 * Http::fake() APPENDS stub callbacks and the FIRST match wins, so calling it
 * again to change a '*' catch-all does nothing — the original keeps answering.
 * Tests here that need the vendor to accept a publish and then refuse a
 * withdrawal flip $refuseDeletes instead of re-faking.
 *
 * Every create also needs a DISTINCT id: humanity_shift_id is UNIQUE, so handing
 * the same one back twice fails on the index rather than in the code under test.
 */
function fakeRangeWire(object $control): void
{
    $n = 0;

    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*' => function ($request) use (&$n, $control) {
            if ($request->method() === 'DELETE') {
                return $control->refuseDeletes
                    ? Http::response(['status' => 0, 'message' => 'nope'], 500)
                    : Http::response(['status' => 1], 200);
            }

            $n++;

            return Http::response(['status' => 1, 'data' => ['id' => 'HS-'.(700 + $n)]], 200);
        },
    ]);
}

/** Mutable so one stub can change its mind mid-test. See fakeRangeWire(). */
function wireControl(bool $refuseDeletes = false): object
{
    $control = new stdClass;
    $control->refuseDeletes = $refuseDeletes;

    fakeRangeWire($control);

    return $control;
}

/** A draft shift on a given business date at the demo store. */
function shiftOn(string $businessDate, int $hourOffset = 0): Shift
{
    $template = Shift::query()
        ->whereNotNull('employee_id')
        ->whereNotNull('position_id')
        ->whereDoesntHave('workSegments')
        ->orderBy('id')
        ->firstOrFail();

    return Shift::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $template->employee_id,
        'position_id' => $template->position_id,
        'business_date' => $businessDate,
        'start_at' => now()->parse($businessDate.' 17:00:00')->addHours($hourOffset),
        'end_at' => now()->parse($businessDate.' 21:00:00')->addHours($hourOffset),
    ]);
}

function unpublishAll(string $from, string $to)
{
    return test()->post(route('board.shifts.unpublish-all'), [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $from, 'to' => $to,
    ]);
}

function deleteAll(string $from, string $to)
{
    return test()->delete(route('board.shifts.destroy-all'), [
        'store_id' => DemoSeeder::STORE_ID, 'from' => $from, 'to' => $to,
    ]);
}

/** Soft-deleted: gone from the board, still there for the punches. */
function isSoftDeleted(Shift $shift): bool
{
    return ! Shift::query()->whereKey($shift->id)->exists()
        && (bool) Shift::withTrashed()->find($shift->id)?->trashed();
}

// ── unpublish all ───────────────────────────────────────────────────────

it('unlocks every published shift in the range from one press', function () {
    wireControl();

    $shifts = collect([0, 2, 4])->map(function (int $offset): Shift {
        $shift = shiftOn(test()->today, $offset);
        test()->publisher->push($shift);

        return $shift->fresh();
    });

    expect($shifts->every(fn (Shift $s): bool => $s->publish_state->value === 'published'))->toBeTrue();

    unpublishAll($this->today, $this->today)->assertRedirect();

    foreach ($shifts as $shift) {
        expect($shift->fresh()->publish_state->value)->toBe('unlocked');
    }

    expect(session('ok'))->toContain('3 shifts unpublished');
});

it('sends nothing to Humanity when it unlocks a range', function () {
    wireControl();

    $shift = shiftOn($this->today);
    $this->publisher->push($shift);

    // Counted rather than re-faked: Http::fake() appends, so a second catch-all
    // would never answer and Http::assertNothingSent() would be asserting about
    // the publish above instead.
    $afterPublish = count(Http::recorded());

    unpublishAll($this->today, $this->today)->assertRedirect();

    /*
     * THE HALF PEOPLE GET WRONG. Unlocking is local — the shifts stay live on
     * everybody's roster, and the next publish sends PUTs over the same shifts.
     * If this ever started withdrawing, a manager opening a week for editing
     * would blank the store's roster until they remembered to republish.
     */
    expect(count(Http::recorded()))->toBe($afterPublish);

    expect($shift->fresh()->humanity_shift_id)->not->toBeNull()
        ->and($shift->fresh()->payload_fingerprint)->not->toBeNull();
});

it('leaves a published shift outside the range locked', function () {
    wireControl();

    $inside = shiftOn($this->today);
    $outside = shiftOn(now()->parse($this->today)->addDays(9)->toDateString());

    $this->publisher->push($inside);
    $this->publisher->push($outside);

    unpublishAll($this->today, $this->today)->assertRedirect();

    expect($inside->fresh()->publish_state->value)->toBe('unlocked')
        // The whole point of a range. Nine days out is somebody else's week.
        ->and($outside->fresh()->publish_state->value)->toBe('published');
});

// ── delete all ──────────────────────────────────────────────────────────

it('deletes every shift in the range and nothing outside it', function () {
    wireControl();

    $inside = shiftOn($this->today);
    $outside = shiftOn(now()->parse($this->today)->addDays(9)->toDateString());

    deleteAll($this->today, $this->today)->assertRedirect();

    expect(isSoftDeleted($inside))->toBeTrue()
        ->and(isSoftDeleted($outside))->toBeFalse();
});

it('withdraws from Humanity before deleting locally', function () {
    wireControl();

    $shift = shiftOn($this->today);
    $this->publisher->push($shift);
    $humanityId = $shift->fresh()->humanity_shift_id;
    expect($humanityId)->not->toBeNull();

    deleteAll($this->today, $this->today)->assertRedirect();

    // Deleting a published shift without telling Humanity leaves it live on the
    // employee's roster, with the row that held its id soft-deleted — so nothing
    // could ever clean it up. Somebody turns up for a shift cancelled last week.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), (string) $humanityId));

    expect(isSoftDeleted($shift))->toBeTrue();
});

it('does not delete a shift Humanity refused to release', function () {
    $wire = wireControl();

    $shift = shiftOn($this->today);
    $this->publisher->push($shift);
    expect($shift->fresh()->humanity_shift_id)->not->toBeNull();

    // The vendor accepted the publish and now refuses the withdrawal.
    $wire->refuseDeletes = true;

    deleteAll($this->today, $this->today)->assertRedirect();

    /*
     * STILL HERE, on purpose. Humanity is holding a shift we could not withdraw,
     * so deleting the row would strand it there with nothing left to retry from.
     * Leaving it gives the manager a shift they can try again — the recoverable
     * half of the trade — and the flash says which one and why.
     */
    expect(isSoftDeleted($shift))->toBeFalse();
    expect(session('err'))->toContain('could NOT be withdrawn');
});

it('finishes the rest of the range when one withdrawal fails', function () {
    $wire = wireControl();

    $published = shiftOn($this->today, 0);
    $draft = shiftOn($this->today, 2);
    $this->publisher->push($published);

    $wire->refuseDeletes = true;

    deleteAll($this->today, $this->today)->assertRedirect();

    /*
     * PARTIAL SUCCESS IS THE NORMAL FAILURE MODE. Abandoning the sweep at the
     * first refusal would leave a manager pressing delete repeatedly, each press
     * stopping at the same shift — and the drafts it never reached are ones
     * Humanity has no opinion about at all.
     */
    expect(isSoftDeleted($published))->toBeFalse()
        ->and(isSoftDeleted($draft))->toBeTrue();
});

it('keeps worked hours pointing at the shifts it deletes', function () {
    wireControl();

    $shift = shiftOn($this->today);

    $segment = WorkSegment::query()->create([
        'employee_id' => $shift->employee_id,
        'store_id' => $shift->store_id,
        'shift_id' => $shift->id,
        'business_date' => $this->today,
        'time_in' => now()->parse($this->today.' 17:00:00'),
        'time_out' => now()->parse($this->today.' 21:00:00'),
        'hours' => 4,
    ]);

    deleteAll($this->today, $this->today)->assertRedirect();

    /*
     * SOFT DELETE, so work_segments.shift_id keeps pointing at the row — the FK's
     * ON DELETE SET NULL only fires on a hard delete. A reconciliation somebody
     * made by hand is evidence, and a range delete must not destroy it.
     */
    expect(isSoftDeleted($shift))->toBeTrue()
        ->and($segment->fresh()->shift_id)->toBe($shift->id);

    expect(session('ok'))->toContain('punch(es) still reference');
});

it('does not follow a series past the range', function () {
    wireControl();

    $seriesId = (string) Str::ulid();

    $inside = shiftOn($this->today);
    $nextMonth = shiftOn(now()->parse($this->today)->addDays(35)->toDateString());

    $inside->forceFill(['series_id' => $seriesId])->save();
    $nextMonth->forceFill(['series_id' => $seriesId])->save();

    deleteAll($this->today, $this->today)->assertRedirect();

    /*
     * THE TRAP THIS AVOIDS. ShiftService::delete() follows series_id with a
     * 'following' rule, so routing a range delete through it would have "delete
     * this week" quietly remove next month's occurrences of every repeating
     * shift. The range IS the selection here.
     */
    expect(isSoftDeleted($inside))->toBeTrue()
        ->and(isSoftDeleted($nextMonth))->toBeFalse();
});

it('reports honestly when the range is empty', function () {
    $empty = now()->parse($this->today)->addDays(60)->toDateString();

    deleteAll($empty, $empty)->assertRedirect();

    expect(session('ok'))->toContain('No shifts in view');
});

it('says there is nothing to unpublish rather than refusing', function () {
    // A no-op somebody may reasonably press. Answering an error would teach them
    // to distrust the button.
    $empty = now()->parse($this->today)->addDays(60)->toDateString();

    unpublishAll($empty, $empty)->assertRedirect();

    expect(session('ok'))->toContain('No shifts in view');
});

// ── the console ─────────────────────────────────────────────────────────

it('offers the range delete with the count in the label', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $response = $this->get('/board/week?store='.DemoSeeder::STORE_ID.'&view=planned')->assertOk();

    // A count in the label is the safety mechanism: "Delete all shifts" says
    // nothing about scope, "Delete all 14" cannot be misread.
    $response->assertSee('Delete all', false)
        ->assertSee(route('board.shifts.destroy-all'), false);

    // UNPUBLISH IS NO LONGER ON THIS CARD. It moved to the Humanity card, which
    // is now one button cycling Publish -> Unpublish -> Republish — and it only
    // appears once something is actually locked, which nothing in this fixture
    // is. PublishCycleButtonTest covers each state.
    $response->assertDontSee('Unpublish all', false)
        ->assertDontSee(route('board.shifts.unpublish-all'), false);
});

it('spells out the Humanity consequence in the delete confirm', function () {
    wireControl();

    $shift = shiftOn($this->today);
    $this->publisher->push($shift);

    $response = $this->get('/board?store='.DemoSeeder::STORE_ID.'&date='.$this->today)->assertOk();

    // Not "are you sure": what actually happens, to Humanity and to the hours.
    $response->assertSee('live in Humanity and will be withdrawn', false)
        ->assertSee('soft delete', false);
});

it('refuses a range that runs backwards', function () {
    deleteAll($this->today, now()->parse($this->today)->subDay()->toDateString())
        ->assertSessionHasErrors('to');
});
