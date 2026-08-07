<?php

use App\Models\Shift;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The console, end to end
|--------------------------------------------------------------------------
|
| These drive the same routes the browser does, so they cover the layer the
| schema tests deliberately skip: the services, the controller and the view.
| Where SchedulingSchemaTest asserts the migrations are right, these assert
| the application built on them behaves.
|
*/

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
    $this->today = app(BusinessDay::class)
        ->toLocal(DemoSeeder::STORE_ID, now())
        ->toDateString();
});

it('redirects the root to the board', function () {
    $this->get('/')->assertRedirect('/board');
});

it('renders the board with the seeded day', function () {
    $this->get('/board')
        ->assertOk()
        ->assertSee('Store #'.DemoSeeder::STORE_ID)
        ->assertSee('Ada Okafor')
        ->assertSee('Ben Ortiz');
});

it('shows the day as blocked, naming both blocker categories separately', function () {
    $response = $this->get('/board')->assertOk();

    // An open punch has no hours to approve, so it must be reported apart from
    // the unapproved ones rather than folded in with them.
    $response->assertSee('unapproved')
        ->assertSee('open_punch')
        ->assertSee('still clocked in, no hours to approve yet');
});

it('refuses to close a day that still has blockers', function () {
    $this->post('/board/day-close', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
    ])->assertRedirect();

    expect(session('err'))->toContain('cannot close');
});

it('closes the day once every punch is out and approved', function () {
    foreach (WorkSegment::whereNull('time_out')->get() as $open) {
        $this->post("/board/segments/{$open->id}/punch-out");
    }

    $this->post('/board/segments/approve-all', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
    ]);

    $this->post('/board/day-close', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
    ])->assertRedirect();

    expect(session('ok'))->toContain('Day closed');
});

it('adds a shift and flags it when it falls outside availability', function () {
    // Ben's window closes at 21:00; 08:00-10:00 is nowhere near it.
    $ben = \App\Models\Employee::where('first_name', 'Ben')->firstOrFail();

    $this->post('/board/shifts', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
        'employee_id' => $ben->id,
        'start' => '08:00',
        'end' => '10:00',
    ])->assertRedirect();

    $shift = Shift::where('employee_id', $ben->id)->latest('id')->first();

    // Warned, not refused: the row exists.
    expect($shift)->not->toBeNull()
        ->and($shift->availability_check->value)->toBe('outside_availability');
});

it('accepts an end before a start as an overnight shift', function () {
    $cleo = \App\Models\Employee::where('first_name', 'Cleo')->firstOrFail();

    $this->post('/board/shifts', [
        'store_id' => DemoSeeder::STORE_ID,
        'date' => $this->today,
        'employee_id' => $cleo->id,
        'start' => '22:00',
        'end' => '02:00',
    ])->assertRedirect();

    $shift = Shift::where('employee_id', $cleo->id)->latest('id')->first();

    // Four hours, and it belongs to the day it STARTED on.
    expect(round($shift->paidHours(), 2))->toBe(4.0)
        ->and($shift->business_date->toDateString())->toBe($this->today);
});

it('splits a shift into two rows and does not pay for the gap', function () {
    // An UNSPLIT shift: Ada's is already a two-part split in the seed, and
    // splitting that again would correctly produce three parts, not two.
    $part1 = Shift::whereNotNull('employee_id')
        ->whereNull('split_group_id')
        ->orderBy('id')
        ->firstOrFail();
    $before = $part1->paidHours();

    $this->post("/board/shifts/{$part1->id}/split", [
        'gap_minutes' => 45,
        'length_minutes' => 60,
    ])->assertRedirect();

    $parts = Shift::where('split_group_id', $part1->fresh()->split_group_id)
        ->orderBy('split_part')
        ->get();

    // Two rows, never one row with a hole in it. The 45 minute gap is unpaid
    // and is NOT recorded as a break — total paid is part 1 plus one hour.
    expect($parts)->toHaveCount(2)
        ->and($parts->pluck('split_part')->all())->toBe([1, 2])
        ->and(round($parts->sum(fn (Shift $s) => $s->paidHours()), 2))->toBe(round($before + 1.0, 2))
        ->and($parts[1]->unpaid_break_minutes)->toBe(0);
});

it('keeps the punches when the shift they were planned against is soft-deleted', function () {
    $shift = Shift::has('workSegments')->firstOrFail();
    $punchCount = $shift->workSegments()->count();

    $this->post("/board/shifts/{$shift->id}", ['_method' => 'DELETE'])->assertRedirect();

    // Soft delete: the row is hidden, so the punches keep pointing at it and
    // the pairing survives a restore. shift_id only drops to NULL on a HARD
    // delete, which is when the ON DELETE SET NULL rule fires.
    expect(Shift::find($shift->id))->toBeNull()
        ->and(Shift::withTrashed()->find($shift->id))->not->toBeNull()
        ->and(WorkSegment::where('shift_id', $shift->id)->count())->toBe($punchCount);
});

it('round-trips an edited punch in store-local time, not UTC', function () {
    $segment = WorkSegment::whereNotNull('time_out')->firstOrFail();

    $this->put("/board/segments/{$segment->id}", [
        'date' => $this->today,
        'time_in' => '09:30',
        'time_out' => '13:45',
        'reapprove' => 0,
    ])->assertRedirect();

    $bd = app(BusinessDay::class);
    $fresh = $segment->fresh();

    // The form collects wall-clock time. correctTimes() takes UTC instants and
    // parses a bare string AS UTC, so without an explicit conversion every
    // edit shifted by the store's offset — 09:30 was landing as 05:30.
    expect($bd->toLocal(DemoSeeder::STORE_ID, $fresh->time_in)->format('H:i'))->toBe('09:30')
        ->and($bd->toLocal(DemoSeeder::STORE_ID, $fresh->time_out)->format('H:i'))->toBe('13:45')
        // A correction clears the approval unless the caller re-approves.
        ->and((bool) $fresh->manager_approval)->toBeFalse();
});

it('keeps the approval when an edit explicitly re-approves', function () {
    $segment = WorkSegment::whereNotNull('time_out')->firstOrFail();

    $this->put("/board/segments/{$segment->id}", [
        'date' => $this->today,
        'time_in' => '10:00',
        'time_out' => '14:00',
        'reapprove' => 1,
    ])->assertRedirect();

    expect((bool) $segment->fresh()->manager_approval)->toBeTrue();
});

it('edits a planned shift in store-local time', function () {
    $shift = Shift::whereNotNull('employee_id')->firstOrFail();

    $this->put("/board/shifts/{$shift->id}", [
        'date' => $this->today,
        'employee_id' => $shift->employee_id,
        'start' => '12:15',
        'end' => '18:45',
    ])->assertRedirect();

    $bd = app(BusinessDay::class);
    $fresh = $shift->fresh();

    expect($bd->toLocal(DemoSeeder::STORE_ID, $fresh->start_at)->format('H:i'))->toBe('12:15')
        ->and($bd->toLocal(DemoSeeder::STORE_ID, $fresh->end_at)->format('H:i'))->toBe('18:45');
});

it('records a reversal in the decision trail rather than overwriting it', function () {
    $request = \App\Models\EmployeeRequest::where('status', 'approved')->firstOrFail();

    $this->post("/board/requests/{$request->id}/decide", ['decision' => 'cancelled'])
        ->assertRedirect();

    expect($request->fresh()->status->value)->toBe('cancelled')
        ->and($request->decisions()->pluck('decision')->map(fn ($d) => $d->value)->all())
        ->toBe(['approved', 'cancelled']);
});
