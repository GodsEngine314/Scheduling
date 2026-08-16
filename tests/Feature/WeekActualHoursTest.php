<?php

use App\Models\Employee;
use App\Models\WorkSegment;
use App\Services\Scheduling\LaborCostEstimator;
use App\Services\Scheduling\WorkSegmentSyncService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The week's ACTUAL hours
|--------------------------------------------------------------------------
|
| The week grid reads the same seven days twice: the shifts we planned, and the
| punches TCP recorded against them. These pin the half that is easy to get
| subtly wrong.
|
| THE OPEN PUNCH IS THE POINT. Somebody clocked in and has not left, so the row
| has a time_in, no time_out, and no hours. It must show as exactly that —
| approving it, costing it, or totalling it as a zero-hour day are three
| different ways of reporting a day as settled while somebody is still working.
|
*/

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
    $this->bd = app(BusinessDay::class);
    $this->today = $this->bd->toLocal(DemoSeeder::STORE_ID, now())->toDateString();

    signIn();
});

/** The seeded punch nobody has clocked out of. */
function openPunch(): WorkSegment
{
    return WorkSegment::query()->whereNull('time_out')->firstOrFail();
}

/** A finished punch nobody has signed off. */
function unapprovedPunch(): WorkSegment
{
    return WorkSegment::query()
        ->whereNotNull('time_out')
        ->where('manager_approval', false)
        ->firstOrFail();
}

// ── the two views ───────────────────────────────────────────────────────

it('reads the same week three ways, defaulting to plan against actual', function () {
    // The default is the COMBINED view: both stacks in one cell, because the
    // question a week grid is read for is how the two compare.
    $this->get('/board/week')
        ->assertOk()
        ->assertSee('Plan vs actual')
        ->assertSee('data-shift=', false)
        ->assertSee('data-seg=', false);

    $planned = $this->get('/board/week?view=planned')->assertOk();

    $planned->assertSee('Planned shifts')
        ->assertSee('Actual hours')
        ->assertSee('Add a planned shift')
        // The planned tab is the drag surface, and only the drag surface.
        ->assertSee('data-shift=', false)
        ->assertDontSee('data-seg=', false);

    $this->get('/board/week?view=actual')
        ->assertOk()
        ->assertSee('Record actual hours by hand')
        ->assertSee('data-seg=', false)
        // Nothing to drag on the actual side: a punch is a record of something
        // that happened, not a plan to be rearranged.
        ->assertDontSee('data-shift=', false);
});

it('shows a punch that is still open as a clock-in with no clock-out', function () {
    $open = openPunch();
    $inAt = $this->bd->toLocal(DemoSeeder::STORE_ID, $open->time_in)->format('H:i');

    $page = $this->get('/board/week?view=actual')->assertOk();

    $page->assertSee('still in')
        ->assertSee('data-open="1"', false)
        ->assertSee('data-in="'.$inAt.'"', false)
        // The chip carries no clock-out because there is not one yet.
        ->assertSee('data-out=""', false);
});

it('offers no approve button on an open punch, because there are no hours yet', function () {
    $open = openPunch();
    $closed = unapprovedPunch();

    $page = $this->get('/board/week?view=actual')->assertOk();

    // The closed one is the work: one click, one employee.
    $page->assertSee("/board/segments/{$closed->id}/approve", false)
        ->assertDontSee("/board/segments/{$open->id}/approve", false);
});

it('refuses to approve an open punch even when the route is called directly', function () {
    $open = openPunch();

    $this->post("/board/segments/{$open->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('err');

    expect((bool) $open->fresh()->manager_approval)->toBeFalse();
});

it('carries the view across the store and week controls', function () {
    // Picking a store while reading actual hours must not drop you back on the
    // planned tab you did not ask for.
    $this->get('/board/week?view=actual')
        ->assertOk()
        ->assertSee('name="view" value="actual"', false)
        ->assertSee('view=actual', false);
});

// ── approving, correcting, deleting from the week ───────────────────────

it('approves one punch from the week view and queues the approval to TCP', function () {
    $segment = unapprovedPunch();

    $this->post("/board/segments/{$segment->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('ok');

    $fresh = $segment->fresh();

    expect((bool) $fresh->manager_approval)->toBeTrue()
        ->and($fresh->approved_at)->not->toBeNull()
        // "Approving Hours ... PUT /worksegments/{id}" — an approval that never
        // reaches TCP means payroll pays a number the timeclock disagrees with.
        ->and($fresh->tcp_sync_state->value)->toBe('pending');
});

it('clears the approval when the times are corrected, unless told to keep it', function () {
    $segment = unapprovedPunch();
    $this->post("/board/segments/{$segment->id}/approve")->assertRedirect();

    $this->put("/board/segments/{$segment->id}", [
        'date' => $this->today,
        'time_in' => '16:00',
        'time_out' => '22:00',
    ])->assertRedirect();

    $corrected = $segment->fresh();

    expect((bool) $corrected->manager_approval)->toBeFalse()
        ->and($corrected->times_corrected_at)->not->toBeNull()
        ->and($this->bd->toLocal(DemoSeeder::STORE_ID, $corrected->time_in)->format('H:i'))->toBe('16:00')
        // Six hours less the 30 minute break TCP reported.
        ->and((float) $corrected->hours)->toBe(5.5);

    $this->put("/board/segments/{$segment->id}", [
        'date' => $this->today,
        'time_in' => '16:00',
        'time_out' => '21:30',
        'reapprove' => 1,
    ])->assertRedirect();

    expect((bool) $segment->fresh()->manager_approval)->toBeTrue();
});

it('closes an open punch by filling in the clock-out', function () {
    $open = openPunch();

    $this->put("/board/segments/{$open->id}", [
        'date' => $this->today,
        'time_in' => $this->bd->toLocal(DemoSeeder::STORE_ID, $open->time_in)->format('H:i'),
        'time_out' => '21:30',
    ])->assertRedirect();

    $closed = $open->fresh();

    expect($closed->time_out)->not->toBeNull()
        ->and($closed->hours)->not->toBeNull();
});

it('soft-deletes a punch, because a punch is evidence', function () {
    $segment = unapprovedPunch();

    $this->delete("/board/segments/{$segment->id}")->assertRedirect()->assertSessionHas('ok');

    expect(WorkSegment::query()->whereKey($segment->id)->exists())->toBeFalse()
        ->and(WorkSegment::withTrashed()->whereKey($segment->id)->exists())->toBeTrue();
});

// ── recording hours nobody punched ──────────────────────────────────────

it('records hours by hand as an unapproved manual_create, queued for TCP', function () {
    $employee = Employee::query()->firstOrFail();

    $this->post('/board/segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employee->id,
        'date' => $this->today,
        'time_in' => '09:00',
        'time_out' => '12:30',
        'break_minutes' => 30,
    ])->assertRedirect()->assertSessionHas('ok');

    $segment = WorkSegment::latest('id')->firstOrFail();

    expect($segment->origin->value)->toBe('manual_create')
        // Hours somebody typed are not hours somebody approved.
        ->and((bool) $segment->manager_approval)->toBeFalse()
        // NULL until a POST to TCP succeeds: a failed push has to leave visible
        // hours behind, not lose them.
        ->and($segment->tcp_segment_id)->toBeNull()
        ->and($segment->tcp_sync_state->value)->toBe('pending')
        ->and($segment->business_date->toDateString())->toBe($this->today)
        ->and((float) $segment->hours)->toBe(3.0)
        // The form collects STORE WALL CLOCK. Read as UTC it would land at the
        // store's offset and file the punch on the wrong day come the evening.
        ->and($this->bd->toLocal(DemoSeeder::STORE_ID, $segment->time_in)->format('H:i'))->toBe('09:00');
});

it('records somebody still in the store when the clock-out is left empty', function () {
    $employee = Employee::query()->firstOrFail();

    $this->post('/board/segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employee->id,
        'date' => $this->today,
        'time_in' => '17:00',
    ])->assertRedirect()->assertSessionHas('ok');

    $segment = WorkSegment::latest('id')->firstOrFail();

    // An empty clock-out is not an incomplete form: it is an open punch, and it
    // has no hours to total or approve.
    expect($segment->time_out)->toBeNull()
        ->and($segment->hours)->toBeNull()
        ->and($segment->isOpenPunch())->toBeTrue();
});

it('rolls a hand-entered punch past midnight rather than refusing it', function () {
    $employee = Employee::query()->firstOrFail();
    $tomorrow = now()->parse($this->today)->addDay()->toDateString();

    $this->post('/board/segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employee->id,
        'date' => $this->today,
        'time_in' => '21:00',
        'time_out' => '01:00',
    ])->assertRedirect()->assertSessionHas('ok');

    $segment = WorkSegment::latest('id')->firstOrFail();

    expect((float) $segment->hours)->toBe(4.0)
        // It belongs to the day it STARTED, even though it ended on the next.
        ->and($segment->business_date->toDateString())->toBe($this->today)
        ->and($this->bd->toLocal(DemoSeeder::STORE_ID, $segment->time_out)->toDateString())->toBe($tomorrow);
});

it('refuses a hand-entered punch whose clocks are identical', function () {
    $employee = Employee::query()->firstOrFail();
    $before = WorkSegment::count();

    // Rolled forward as an overnight punch this would be a 24-hour shift, which
    // is a typo rather than a day's work.
    $this->post('/board/segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employee->id,
        'date' => $this->today,
        'time_in' => '17:00',
        'time_out' => '17:00',
    ])->assertSessionHasErrors('time_out');

    expect(WorkSegment::count())->toBe($before);
});

// ── pulling the week from TCP ───────────────────────────────────────────

it('pulls the whole week from TCP in one call, not seven', function () {
    // TUESDAY-first, matching BoardController::week(). The grid runs Tuesday to
    // Monday, so the range the sync is asked for has to run the same way.
    $monday = now()->parse($this->today)->startOfWeek(Carbon\CarbonInterface::TUESDAY)->toDateString();
    $sunday = now()->parse($monday)->addDays(6)->toDateString();

    $this->mock(WorkSegmentSyncService::class)
        ->shouldReceive('syncRange')
        ->once()
        ->with($monday, $sunday, DemoSeeder::STORE_ID)
        ->andReturn([
            'fetched' => 4, 'created' => 4, 'updated' => 0,
            'unchanged' => 0, 'held' => 0, 'skipped' => [],
        ]);

    $this->post('/board/pull-segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $monday,
        'to' => $sunday,
    ])->assertRedirect()->assertSessionHas('ok');
});

it('still pulls a single day when no span is given', function () {
    $this->mock(WorkSegmentSyncService::class)
        ->shouldReceive('syncRange')
        ->once()
        ->with($this->today, $this->today, DemoSeeder::STORE_ID)
        ->andReturn([
            'fetched' => 0, 'created' => 0, 'updated' => 0,
            'unchanged' => 0, 'held' => 0, 'skipped' => [],
        ]);

    $this->post('/board/pull-segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
    ])->assertRedirect();
});

// ── what the week totals say ────────────────────────────────────────────

it('totals worked hours without inventing any for the open punch', function () {
    $segments = WorkSegment::query()->forStoreBetween(
        DemoSeeder::STORE_ID,
        now()->parse($this->today)->startOfWeek(Carbon\CarbonInterface::TUESDAY)->toDateString(),
        now()->parse($this->today)->startOfWeek(Carbon\CarbonInterface::TUESDAY)->addDays(6)->toDateString(),
    )->get();

    $totals = app(LaborCostEstimator::class)->actualFor($segments, DemoSeeder::STORE_ID);

    $closed = $segments->whereNotNull('time_out');

    expect($totals['open_punches'])->toBe($segments->whereNull('time_out')->count())
        ->and($totals['open_punches'])->toBeGreaterThan(0)
        ->and($totals['actual_hours'])->toBe(round($closed->sum(fn ($g) => (float) $g->hours), 2))
        ->and($totals['unapproved'])->toBe($closed->where('manager_approval', false)->count());

    // The open punch's owner has a row total, and it counts none of the hours
    // they have not finished working.
    $open = openPunch();
    $row = $totals['per_employee'][(int) $open->employee_id];

    expect($row['open_punches'])->toBeGreaterThan(0)
        ->and($row['hours'])->toBe(round(
            $closed->where('employee_id', $open->employee_id)->sum(fn ($g) => (float) $g->hours),
            2,
        ));
});

it('gives a row to somebody who punched here but is off the roster', function () {
    // Terminated on Wednesday, but Monday and Tuesday still happened. Hiring's
    // termination drops them off the roster retroactively, and rendering only
    // the roster would drop their chips while their hours kept counting in the
    // header total — a week that does not add up, invisibly.
    $segment = unapprovedPunch();
    $employee = $segment->employee;

    $employee->forceFill([
        'current_status' => 'terminated',
        'current_status_effective_date' => $this->today,
    ])->save();

    $page = $this->get('/board/week?view=actual')->assertOk();

    $page->assertSee($employee->fullName())
        ->assertSee('off roster')
        ->assertSee('data-seg="'.$segment->id.'"', false);

    // And they are gone entirely from the PURE planning view: the roster is
    // still the roster, and they cannot be scheduled. (The combined view does
    // show them, correctly — their hours are real and have to land somewhere.)
    $this->get('/board/week?view=planned')->assertOk()->assertDontSee($employee->fullName());
});
