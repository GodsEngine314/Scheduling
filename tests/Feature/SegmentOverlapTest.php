<?php

use App\Exceptions\SchedulingException;
use App\Models\Employee;
use App\Models\Position;
use App\Models\WorkSegment;
use App\Services\Scheduling\WorkSegmentService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| One person cannot work two stretches at once
|--------------------------------------------------------------------------
|
| A duplicate or overlapping punch is REFUSED, not warned about — the opposite of
| how an overlapping SHIFT is treated, and the asymmetry is deliberate:
|
|   A shift is a PLAN. Double-booking somebody is sometimes what a manager
|       means, so ShiftService::conflicts() surfaces it and saves anyway.
|   A segment is a RECORD OF FACT. Nobody worked two overlapping stretches, so
|       an overlap is a mistake — and one that silently PAYS THEM TWICE, because
|       the day close and the labour cost both sum `hours` across segments.
|
| The guard is on the MANUAL paths only. WorkSegmentSyncService writes its own
| rows straight through the model, because TCP is the source of truth for punches
| and refusing what a real timeclock reports would lose the evidence.
|
*/

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->segments = app(WorkSegmentService::class);
    $this->businessDay = app(BusinessDay::class);
    $this->today = $this->businessDay->toLocal(DemoSeeder::STORE_ID, now())->toDateString();
    $this->position = Position::query()->value('id');

    // Nobody the demo has already punched for, so each test builds its own
    // history rather than colliding with Ada's split shift.
    $this->employee = Employee::query()
        ->whereDoesntHave('workSegments')
        ->orderBy('id')
        ->firstOrFail();

    signIn();
});

/** A punch at a known store-local wall clock. */
function enterPunch(string $in, ?string $out = '21:00', ?int $employeeId = null): WorkSegment
{
    $test = test();

    return $test->segments->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employeeId ?? $test->employee->id,
        'position_id' => $test->position,
        'time_in_local' => "{$test->today} {$in}:00",
        'time_out_local' => $out === null ? null : "{$test->today} {$out}:00",
    ]);
}

// ── the same punch twice ────────────────────────────────────────────────

it('refuses an identical punch and says it would pay the hours twice', function () {
    enterPunch('17:00', '21:00');

    expect(fn () => enterPunch('17:00', '21:00'))
        ->toThrow(
            SchedulingException::class,
            'This is the same punch as work segment',
        );

    expect(WorkSegment::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('names paying twice as the reason, because that is what actually goes wrong', function () {
    enterPunch('17:00', '21:00');

    try {
        enterPunch('17:00', '21:00');
    } catch (SchedulingException $e) {
        expect($e->getMessage())->toContain('pay these hours twice')
            ->and($e->getMessage())->toContain('17:00')
            ->and($e->getMessage())->toContain('21:00');
    }
});

// ── overlapping, not identical ──────────────────────────────────────────

it('refuses a punch that starts inside an existing one', function () {
    enterPunch('17:00', '21:00');

    expect(fn () => enterPunch('19:00', '23:00'))
        ->toThrow(SchedulingException::class, 'overlaps work segment');
});

it('refuses a punch that swallows an existing one', function () {
    enterPunch('18:00', '20:00');

    expect(fn () => enterPunch('17:00', '23:00'))
        ->toThrow(SchedulingException::class, 'overlaps work segment');
});

it('allows a punch that merely touches the end of another', function () {
    enterPunch('13:00', '17:00');

    // 17:00-21:00 against 13:00-17:00. Back to back is not an overlap — a split
    // shift's two halves meet like this, and refusing it would break the day.
    $second = enterPunch('17:00', '21:00');

    expect($second->exists)->toBeTrue()
        ->and(WorkSegment::query()->where('employee_id', $this->employee->id)->count())->toBe(2);
});

it('allows the same window for a DIFFERENT employee', function () {
    enterPunch('17:00', '21:00');

    $other = Employee::query()
        ->where('id', '!=', $this->employee->id)
        ->orderBy('id')
        ->firstOrFail();

    // The demo punches for most of its four people. This test is about the
    // EMPLOYEE dimension of the guard, so clear their history outright rather
    // than hunting for somebody who happens to be unpunched.
    WorkSegment::query()->where('employee_id', $other->id)->forceDelete();

    // Two people on the same shift is a rota, not a mistake.
    expect(enterPunch('17:00', '21:00', $other->id)->exists)->toBeTrue();
});

// ── open punches ────────────────────────────────────────────────────────

it('refuses a second punch while the employee is still clocked in', function () {
    enterPunch('17:00', null);

    // AN OPEN PUNCH HAS NO END, so it runs forever until it is closed. This is
    // the most common way the guard is hit in practice.
    expect(fn () => enterPunch('19:00', '23:00'))
        ->toThrow(SchedulingException::class, 'still open — clock it out first');
});

it('refuses a new open punch on top of a closed one it would swallow', function () {
    enterPunch('17:00', '21:00');

    // Open-ended from 19:00, so it runs through the existing punch's tail.
    expect(fn () => enterPunch('19:00', null))
        ->toThrow(SchedulingException::class, 'overlaps work segment');
});

it('allows an open punch that starts after everything already recorded', function () {
    enterPunch('09:00', '13:00');

    expect(enterPunch('17:00', null)->exists)->toBeTrue();
});

// ── corrections ─────────────────────────────────────────────────────────

it('refuses a correction that walks a punch onto its neighbour', function () {
    $first = enterPunch('09:00', '13:00');
    $second = enterPunch('17:00', '21:00');

    // Dragging the second punch's start back to 12:00 would overlap the first.
    expect(fn () => $this->segments->correctTimes(
        $second,
        $this->businessDay->combine(DemoSeeder::STORE_ID, $this->today, '12:00:00'),
    ))->toThrow(SchedulingException::class, 'overlaps work segment');

    // Untouched: a refused correction must not half-apply.
    expect($this->businessDay->toLocal(DemoSeeder::STORE_ID, $second->fresh()->time_in)->format('H:i'))
        ->toBe('17:00')
        ->and($first->fresh()->exists)->toBeTrue();
});

it('allows a correction that does not collide, and does not count the segment against itself', function () {
    $segment = enterPunch('17:00', '21:00');

    // 17:30-21:00 overlaps the row being corrected, which is the one row that
    // must be excluded — otherwise no correction could ever be made.
    $corrected = $this->segments->correctTimes(
        $segment,
        $this->businessDay->combine(DemoSeeder::STORE_ID, $this->today, '17:30:00'),
    );

    expect($this->businessDay->toLocal(DemoSeeder::STORE_ID, $corrected->time_in)->format('H:i'))
        ->toBe('17:30');
});

// ── the board says why ──────────────────────────────────────────────────

it('shows the reason on the board rather than a bare failure', function () {
    enterPunch('17:00', '21:00');

    $this->post('/board/segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $this->employee->id,
        'position_id' => $this->position,
        'date' => $this->today,
        'time_in' => '19:00',
        'time_out' => '23:00',
    ])->assertRedirect();

    expect(session('err'))->toContain('overlaps work segment')
        ->and(session('err'))->toContain('nobody can work two stretches at once');

    // And nothing was written.
    expect(WorkSegment::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
});

// ── TCP is still the source of truth ────────────────────────────────────

it('does not block a punch arriving from TCP, however it overlaps', function () {
    enterPunch('17:00', '21:00');

    // The sync writes through the model on purpose, bypassing the guard. If a
    // real timeclock reports overlapping punches then that IS what happened, and
    // refusing to store it would lose the evidence a manager needs to fix it.
    $synced = WorkSegment::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $this->employee->id,
        'business_date' => $this->today,
        'time_in' => $this->businessDay->combine(DemoSeeder::STORE_ID, $this->today, '19:00:00'),
        'time_out' => $this->businessDay->combine(DemoSeeder::STORE_ID, $this->today, '23:00:00'),
        'break_minutes' => 0,
        'origin' => 'tcp_sync',
        'match_source' => 'unmatched',
        'tcp_sync_state' => 'synced',
        'manager_approval' => false,
    ]);

    expect($synced->exists)->toBeTrue()
        ->and(WorkSegment::query()->where('employee_id', $this->employee->id)->count())->toBe(2);
});
