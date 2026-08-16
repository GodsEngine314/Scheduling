<?php

use App\Models\Shift;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The week grid runs Tuesday, and each punch says what it is
|--------------------------------------------------------------------------
|
| Two separate things pinned here, both about reading a week at a glance.
|
| THE WEEK STARTS TUESDAY, wherever the anchor date falls inside it. That is
| also what makes the picker work: any date lands on its own week rather than
| starting the grid on whatever day was typed.
|
| THE COLOUR ENCODES WHETHER A PUNCH IS WHOLE, not whether it is approved. A
| signed-off punch and an unsigned one are both real records of hours; a punch
| missing half of itself is a hole in the timesheet. The hard case is that
| "still in the store" and "missed the clock-out" are the SAME row — time_out
| IS NULL — and only the store's date tells them apart.
|
*/

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->bd = app(BusinessDay::class);
    $this->today = $this->bd->toLocal(DemoSeeder::STORE_ID, now())->toDateString();
    $this->employee = App\Models\Employee::query()
        ->where('primary_store_id', DemoSeeder::STORE_ID)
        ->firstOrFail();

    // An empty week to build in. DemoSeeder puts its own shifts and punches
    // around today, and a grid asserting "nothing is flagged" cannot tell its
    // own fixture from the seeder's.
    WorkSegment::query()->forceDelete();
    Shift::query()->forceDelete();

    signIn();
});

/*
 * Assertions go against the TOOLTIP text, not the CSS class.
 *
 * `.chip-seg.missing-in` is declared in the layout stylesheet, which is on every
 * page — so asserting on "missing-in" passes whether a chip rendered or not, and
 * the matching assertDontSee can never pass at all. The tip strings only exist
 * where a chip actually rendered.
 */


function weekPage(string $anchor, string $view = 'actual')
{
    return test()->get(route('board.week', [
        'store' => DemoSeeder::STORE_ID, 'week' => $anchor, 'view' => $view,
    ]));
}

/** A punch on a given local date, optionally still open. */
function punchOn(string $date, string $in = '17:00', ?string $out = '21:00'): WorkSegment
{
    $bd = app(BusinessDay::class);

    return WorkSegment::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => test()->employee->id,
        'time_in' => $bd->combine(DemoSeeder::STORE_ID, $date, $in.':00'),
        'time_out' => $out === null ? null : $bd->combine(DemoSeeder::STORE_ID, $date, $out.':00'),
        'hours' => $out === null ? null : 4,
        'business_date' => $date,
    ]);
}

// ── the week runs Tuesday ───────────────────────────────────────────────

it('starts the grid on Tuesday whatever day the anchor falls on', function () {
    // A Saturday, a Monday and the Tuesday itself all describe one week.
    foreach (['2026-08-11', '2026-08-15', '2026-08-17'] as $anchor) {
        $days = weekPage($anchor)->viewData('days');

        expect($days[0])->toBe('2026-08-11')
            ->and(CarbonImmutable::parse($days[0])->format('l'))->toBe('Tuesday')
            // Tuesday to the following Monday.
            ->and($days[6])->toBe('2026-08-17')
            ->and($days)->toHaveCount(7);
    }
});

it('moves to the next week only once the anchor crosses a Tuesday', function () {
    expect(weekPage('2026-08-17')->viewData('weekStart'))->toBe('2026-08-11');
    expect(weekPage('2026-08-18')->viewData('weekStart'))->toBe('2026-08-18');
});

it('offers Tuesdays and nothing else in the picker', function () {
    $weeks = weekPage($this->today)->viewData('weeks');

    expect($weeks)->not->toBeEmpty();

    foreach ($weeks as $week) {
        expect(CarbonImmutable::parse($week['value'])->format('l'))->toBe('Tuesday');
    }
});

it('always includes the week being viewed, however far back it is', function () {
    // A deep link to last spring must still render with its own week selected.
    $weeks = weekPage('2025-03-08')->viewData('weeks');

    expect(collect($weeks)->pluck('value'))->toContain('2025-03-04');
});

it('marks the store\'s current week in the picker', function () {
    $weeks = weekPage($this->today)->viewData('weeks');
    $current = collect($weeks)->firstWhere('current', true);

    expect($current)->not->toBeNull()
        ->and($current['value'])->toBe(
            CarbonImmutable::parse($this->today)->startOfWeek(CarbonInterface::TUESDAY)->toDateString()
        );
});

// ── what a punch looks like ─────────────────────────────────────────────

it('shows a finished punch as a whole one, with both times', function () {
    punchOn($this->today, '17:00', '21:00');

    weekPage($this->today)
        ->assertOk()
        ->assertSee('chip-seg done', false)
        ->assertSee('17:00–21:00', false);
});

it('shows only the in time for somebody still in the store', function () {
    punchOn($this->today, '17:00', null);

    weekPage($this->today)
        ->assertOk()
        ->assertSee('chip-seg open', false)
        ->assertSee('still in', false)
        // There is no clock-out to show, and inventing one would be a lie about
        // hours nobody has worked yet.
        ->assertDontSee('chip-seg missed', false);
});

it('flags a punch left open on a day that has ended as a missed clock-out', function () {
    $past = CarbonImmutable::parse($this->today)->subDays(2)->toDateString();

    punchOn($past, '17:00', null);

    // Same row shape as "still in" — time_out IS NULL — and a different
    // meaning entirely, decided only by the date.
    weekPage($past)
        ->assertOk()
        ->assertSee('chip-seg missed', false)
        ->assertSee('no out', false)
        ->assertSee('MISSED CLOCK-OUT', false);
});

it('does not call today\'s open punch a missed clock-out', function () {
    punchOn($this->today, '17:00', null);

    weekPage($this->today)
        ->assertOk()
        ->assertDontSee('MISSED CLOCK-OUT', false);
});

// ── the gap with no punch behind it ─────────────────────────────────────

it('flags a past planned shift that was never punched as a missed clock-in', function () {
    $past = CarbonImmutable::parse($this->today)->subDays(2)->toDateString();

    Shift::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $this->employee->id,
        'start_at' => $this->bd->combine(DemoSeeder::STORE_ID, $past, '17:00:00'),
        'end_at' => $this->bd->combine(DemoSeeder::STORE_ID, $past, '21:00:00'),
        'business_date' => $past,
    ]);

    // Without this the cell is simply empty, which reads as "not scheduled"
    // rather than "scheduled, and nobody recorded turning up".
    weekPage($past)
        ->assertOk()
        ->assertSee('MISSED CLOCK-IN', false)
        ->assertSee('no punch', false);
});

it('says nothing about a shift planned for today or later', function () {
    $future = CarbonImmutable::parse($this->today)->addDay()->toDateString();

    Shift::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $this->employee->id,
        'start_at' => $this->bd->combine(DemoSeeder::STORE_ID, $future, '17:00:00'),
        'end_at' => $this->bd->combine(DemoSeeder::STORE_ID, $future, '21:00:00'),
        'business_date' => $future,
    ]);

    // A shift that has not happened has no punch for the obvious reason, and
    // flagging it would paint most of the grid amber.
    weekPage($future)
        ->assertOk()
        ->assertDontSee('MISSED CLOCK-IN', false);
});

it('says nothing when the shift does have hours against it', function () {
    $past = CarbonImmutable::parse($this->today)->subDays(2)->toDateString();

    $shift = Shift::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $this->employee->id,
        'start_at' => $this->bd->combine(DemoSeeder::STORE_ID, $past, '17:00:00'),
        'end_at' => $this->bd->combine(DemoSeeder::STORE_ID, $past, '21:00:00'),
        'business_date' => $past,
    ]);

    punchOn($past, '17:00', '21:00')->forceFill(['shift_id' => $shift->id])->save();

    weekPage($past)
        ->assertOk()
        ->assertSee('chip-seg done', false)
        ->assertDontSee('MISSED CLOCK-IN', false);
});

// ── the planned tab is untouched ────────────────────────────────────────

it('leaves the planned tab alone', function () {
    $past = CarbonImmutable::parse($this->today)->subDays(2)->toDateString();

    Shift::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $this->employee->id,
        'start_at' => $this->bd->combine(DemoSeeder::STORE_ID, $past, '17:00:00'),
        'end_at' => $this->bd->combine(DemoSeeder::STORE_ID, $past, '21:00:00'),
        'business_date' => $past,
    ]);

    // "Was it worked" is a question the planned side does not ask.
    weekPage($past, 'planned')
        ->assertOk()
        ->assertDontSee('MISSED CLOCK-IN', false);
});

// ── plan and actual in one cell ─────────────────────────────────────────

/*
 * The combined view is the DEFAULT, and it is the only one where the two can be
 * compared day by day. The split tabs answered "who is working Thursday" and
 * "did Thursday get worked" separately and could never answer "was Thursday
 * staffed for four and worked by three".
 */

it('defaults to the combined view', function () {
    $page = test()->get(route('board.week', ['store' => DemoSeeder::STORE_ID]))->assertOk();

    expect($page->viewData('view'))->toBe('both')
        ->and($page->viewData('showPlanned'))->toBeTrue()
        ->and($page->viewData('showActual'))->toBeTrue();
});

it('stacks the plan and the punch in the same cell', function () {
    $day = CarbonImmutable::parse($this->today)->subDay()->toDateString();

    $shift = Shift::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $this->employee->id,
        'start_at' => $this->bd->combine(DemoSeeder::STORE_ID, $day, '17:00:00'),
        'end_at' => $this->bd->combine(DemoSeeder::STORE_ID, $day, '21:00:00'),
        'business_date' => $day,
    ]);

    punchOn($day, '17:04', '21:12')->forceFill(['shift_id' => $shift->id])->save();

    weekPage($day, 'both')
        ->assertOk()
        // Both chips, and the rule that separates them — colour alone does not
        // survive a split shift stacked under a split plan.
        ->assertSee('data-shift="'.$shift->id.'"', false)
        ->assertSee('17:04–21:12', false)
        ->assertSee('cell-rule', false);
});

it('keeps the plan draggable in the combined view', function () {
    $day = CarbonImmutable::parse($this->today)->addDay()->toDateString();

    Shift::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $this->employee->id,
        'start_at' => $this->bd->combine(DemoSeeder::STORE_ID, $day, '17:00:00'),
        'end_at' => $this->bd->combine(DemoSeeder::STORE_ID, $day, '21:00:00'),
        'business_date' => $day,
    ]);

    // The drag handlers key off .wk-cell and its data attributes, not off which
    // chips are inside it, so building a plan still works here.
    weekPage($day, 'both')
        ->assertOk()
        ->assertSee('draggable="true"', false)
        ->assertSee('Add a planned shift')
        // ...and so does signing off hours, which the split forced into a
        // separate screen.
        ->assertSee('Record actual hours by hand');
});

it('keeps the open-shifts row, which has no actual counterpart', function () {
    // A punch is always somebody, so this row stays purely planned.
    weekPage($this->today, 'both')->assertOk()->assertSee('— open shifts —', false);
    weekPage($this->today, 'actual')->assertOk()->assertDontSee('— open shifts —', false);
});

it('still lets each side be read on its own', function () {
    weekPage($this->today, 'planned')
        ->assertOk()
        ->assertDontSee('Record actual hours by hand');

    weekPage($this->today, 'actual')
        ->assertOk()
        ->assertDontSee('Add a planned shift');
});
