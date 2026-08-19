<?php

use App\Models\Employee;
use App\Models\Shift;
use App\Models\WorkSegment;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Heads per hour, beside what the hour took
|--------------------------------------------------------------------------
|
| The sales row says what a store took at 5PM. On its own that is half a
| question — $600 is fine with four people on the floor and a disaster with one —
| and working out which meant counting chips down fourteen cells. This is the
| other half: how many people are in the store in each hour, planned and worked.
|
| Three things are pinned hard here, because each is a different way for the row
| to look right and be wrong:
|
|   IT COUNTS PEOPLE, NOT ROWS. Two shifts for one person touching the same hour
|   is one person in the store that hour, and an unfilled shift is nobody at all.
|
|   time_out IS NULL IS TWO DIFFERENT FACTS. Somebody still in the store counts
|   up to now; a punch nobody closed has no honest end, so only its clock-in hour
|   is counted and the day SAYS the rest is under-counted rather than quietly
|   under-counting it.
|
|   IT IS OURS, NOT THE WAREHOUSE'S. The money in that row can be unavailable;
|   the heads never are, and an outage must not take them with it.
|
*/

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();
    // TCP only, by host. A '*' catch-all here would also answer the warehouse,
    // and one test below needs the warehouse to fail on purpose.
    Http::fake(['*tcplusondemand*' => Http::response(['data' => []], 200)]);

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->bd = app(BusinessDay::class);
    $this->today = $this->bd->toLocal(DemoSeeder::STORE_ID, now())->toDateString();

    $crew = Employee::query()
        ->where('primary_store_id', DemoSeeder::STORE_ID)
        ->orderBy('id')
        ->take(2)
        ->get();

    $this->employee = $crew->first();
    $this->other = $crew->last();

    // An empty week to count in. DemoSeeder puts its own shifts and punches
    // around today, and a row asserting "two people at 5PM" cannot tell its own
    // fixture from the seeder's.
    WorkSegment::query()->forceDelete();
    Shift::query()->forceDelete();

    signIn();
});

function headsWeek(string $anchor, string $view = 'planned')
{
    return test()->get(route('board.week', [
        'store' => DemoSeeder::STORE_ID, 'week' => $anchor, 'view' => $view,
    ]));
}

/** A planned shift on a local date. A null employee is an open shift. */
function rosterShift(string $date, string $from, string $to, ?int $employeeId = -1, ?string $endDate = null): Shift
{
    $bd = app(BusinessDay::class);

    return Shift::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employeeId === -1 ? test()->employee->id : $employeeId,
        'start_at' => $bd->combine(DemoSeeder::STORE_ID, $date, $from.':00'),
        'end_at' => $bd->combine(DemoSeeder::STORE_ID, $endDate ?? $date, $to.':00'),
        'business_date' => $date,
    ]);
}

/** A punch on a local date. A null $out is somebody who never clocked out. */
function clockIn(string $date, string $in, ?string $out = null, ?int $employeeId = -1): WorkSegment
{
    $bd = app(BusinessDay::class);

    return WorkSegment::query()->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $employeeId === -1 ? test()->employee->id : $employeeId,
        'time_in' => $bd->combine(DemoSeeder::STORE_ID, $date, $in.':00'),
        'time_out' => $out === null ? null : $bd->combine(DemoSeeder::STORE_ID, $date, $out.':00'),
        'hours' => $out === null ? null : 4,
        'business_date' => $date,
    ]);
}

// ── the planned side ────────────────────────────────────────────────────

it('counts the people planned into each hour, and nobody in the hours nobody is', function () {
    // 17:00–21:00 is in the store for 17, 18, 19 and 20 — and NOT for 21, which
    // is the hour they left at. Counting the hour a shift ends in would put a
    // body in an empty store on every closing shift on the grid.
    rosterShift($this->today, '17:00', '21:00');
    rosterShift($this->today, '17:00', '19:00', employeeId: $this->other->id);

    $day = headsWeek($this->today, 'planned')->assertOk()->viewData('heads')['days'][$this->today];

    expect($day['planned'][16])->toBe(0)
        ->and($day['planned'][17])->toBe(2)
        ->and($day['planned'][18])->toBe(2)
        ->and($day['planned'][19])->toBe(1)
        ->and($day['planned'][20])->toBe(1)
        ->and($day['planned'][21])->toBe(0)
        // The figure under the column: the day's FULLEST hour, never a sum.
        // Somebody on from 10 until 6 is one person, not eight.
        ->and($day['planned_peak'])->toBe(2);
});

it('counts a person once however many of their shifts touch the hour', function () {
    // A split shift handing over at 14:30 puts the same person in both halves of
    // the 14:00 hour. Tallying rows instead of people would report two people in
    // the store, one of whom is the other one.
    rosterShift($this->today, '11:00', '14:30');
    rosterShift($this->today, '14:30', '18:00');

    $day = headsWeek($this->today, 'planned')->viewData('heads')['days'][$this->today];

    expect($day['planned'][14])->toBe(1)
        ->and($day['planned'][13])->toBe(1)
        ->and($day['planned'][15])->toBe(1)
        ->and($day['planned_peak'])->toBe(1);
});

it('keeps an unfilled shift out of the headcount and counts it apart', function () {
    // AN OPEN SHIFT IS NOT A HEAD. A planned "2" that quietly included a shift
    // with no name on it is a rota reading as covered while one of the two does
    // not exist yet.
    rosterShift($this->today, '17:00', '21:00');
    rosterShift($this->today, '17:00', '21:00', employeeId: null);
    rosterShift($this->today, '17:00', '21:00', employeeId: null);

    $response = headsWeek($this->today, 'planned')->assertOk();
    $day = $response->viewData('heads')['days'][$this->today];

    expect($day['planned'][17])->toBe(1)
        // Rows, not people: two unfilled shifts in one hour are two bodies still
        // to find, even though neither is anybody yet.
        ->and($day['open'][17])->toBe(2)
        ->and($day['planned_peak'])->toBe(1)
        ->and($day['open_peak'])->toBe(2);

    // Said on the grid as well as counted, or the number reads as a rota that is
    // one person short rather than one hire short.
    $response->assertSee('+2', false)
        ->assertSee('unfilled shift');
});

// ── the actual side ─────────────────────────────────────────────────────

it('counts the people who actually clocked in for the hour', function () {
    $yesterday = CarbonImmutable::parse($this->today)->subDay()->toDateString();

    clockIn($yesterday, '17:00', '21:00');
    clockIn($yesterday, '20:00', '23:00', employeeId: $this->other->id);

    $day = headsWeek($yesterday, 'actual')->assertOk()->viewData('heads')['days'][$yesterday];

    expect($day['actual'][17])->toBe(1)
        ->and($day['actual'][20])->toBe(2)
        ->and($day['actual'][21])->toBe(1)
        ->and($day['actual'][23])->toBe(0)
        ->and($day['actual_peak'])->toBe(2)
        // Nothing was planned, and the row says so rather than borrowing the
        // punches. Hours nobody rostered are the case this grid exists to show.
        ->and($day['planned_peak'])->toBe(0);
});

it('counts somebody still in the store up to now and not one hour further', function () {
    // A punch with no clock-out on TODAY is somebody in the building. They are
    // counted from their clock-in to now; the rest of their shift has not
    // happened, and drawing it would staff the evening with a guess.
    $this->travelTo($this->bd->combine(DemoSeeder::STORE_ID, $this->today, '20:30:00'));

    clockIn($this->today, '17:00', null);

    $day = headsWeek($this->today, 'actual')->assertOk()->viewData('heads')['days'][$this->today];

    expect($day['actual'][17])->toBe(1)
        ->and($day['actual'][19])->toBe(1)
        // 20:30 is inside the 20:00 hour, so they are here for it...
        ->and($day['actual'][20])->toBe(1)
        // ...and not for the one that has not started.
        ->and($day['actual'][21])->toBe(0)
        ->and($day['still_in'])->toBe(1)
        ->and($day['unknown_out'])->toBe(0);
});

it('counts a punch nobody closed for its clock-in hour only, and says the rest is short', function () {
    // The same NULL time_out, a completely different fact: the day has ended and
    // nobody clocked out. There is no honest end to count to, and inventing one
    // would invent coverage — so only the hour they were certainly here is
    // counted, and the shortfall is stated where it happens.
    $past = CarbonImmutable::parse($this->today)->subDays(2)->toDateString();

    clockIn($past, '17:00', null);

    $response = headsWeek($past, 'actual')->assertOk();
    $day = $response->viewData('heads')['days'][$past];

    expect($day['actual'][17])->toBe(1)
        ->and($day['actual'][18])->toBe(0)
        ->and($day['unknown_out'])->toBe(1)
        ->and($day['still_in'])->toBe(0);

    // Marked on the day it happened, not left to be inferred from a number
    // that is quietly too low.
    $response->assertSee('class="heads-note warn"', false)
        ->assertSee('Only the hour they clocked in is counted');
});

// ── the two of them together ────────────────────────────────────────────

it('shows planned against clocked-in per hour in the combined view', function () {
    // THE COMPARISON THE HEADER MAKES IN HOURS, MADE HERE IN PEOPLE. "3.5 h under
    // plan this week" does not tell you that Thursday at 5PM was staffed for two
    // and worked by one, and one person short at the peak hour is the thing a
    // manager can still do something about.
    $yesterday = CarbonImmutable::parse($this->today)->subDay()->toDateString();

    rosterShift($yesterday, '17:00', '21:00');
    rosterShift($yesterday, '17:00', '21:00', employeeId: $this->other->id);
    clockIn($yesterday, '17:00', '21:00');

    $response = headsWeek($yesterday, 'both')->assertOk();
    $day = $response->viewData('heads')['days'][$yesterday];

    expect($day['planned'][17])->toBe(2)
        ->and($day['actual'][17])->toBe(1);

    // Two numbers with a rule between them, and the row says which is which
    // once at its head rather than fourteen times inside a narrow column.
    $response->assertSee('<span class="sep">/</span>', false)
        ->assertSee('heads:')
        ->assertSee('is three people rostered into that hour');
});

it('shows one number per hour in each of the split views', function () {
    $yesterday = CarbonImmutable::parse($this->today)->subDay()->toDateString();

    rosterShift($yesterday, '17:00', '21:00');
    clockIn($yesterday, '17:00', '21:00');

    // The separator only exists where there are two numbers to separate.
    headsWeek($yesterday, 'planned')->assertOk()
        ->assertSee('heads:')
        ->assertDontSee('<span class="sep">/</span>', false);

    headsWeek($yesterday, 'actual')->assertOk()
        ->assertSee('heads:')
        ->assertDontSee('<span class="sep">/</span>', false);
});

it('never adds the days up — a week of the same three people is three', function () {
    // Headcount does not sum, in either direction. Seven days of "two on at 5PM"
    // is the same two people seven times, and a week total of fourteen would be
    // a staffing figure nothing in the store corresponds to.
    $week = headsWeek($this->today)->viewData('days');

    foreach ($week as $date) {
        rosterShift($date, '17:00', '21:00');
        rosterShift($date, '17:00', '21:00', employeeId: $this->other->id);
    }

    $heads = headsWeek($this->today, 'planned')->assertOk()->viewData('heads');

    expect($heads['planned_peak'])->toBe(2)
        ->and($heads['peak'])->toBe(2);
});

// ── the awkward hours ───────────────────────────────────────────────────

it('puts the hours after midnight in the next day’s column', function () {
    // A shift running 21:00 → 01:00 belongs to Tuesday and puts a body in the
    // store at Wednesday 00:30. Bucketing by business_date instead would draw
    // somebody standing in the store at 01:00 on the TUESDAY — a shift that had
    // not started yet.
    //
    // The default window ends at midnight, so this only shows with a window that
    // reaches into the small hours. Widened here rather than assumed.
    config()->set('lc_data.window.from_hour', 0);

    $days = headsWeek($this->today)->viewData('days');
    [$tuesday, $wednesday] = [$days[0], $days[1]];

    rosterShift($tuesday, '21:00', '01:00', endDate: $wednesday);

    $heads = headsWeek($this->today, 'planned')->assertOk()->viewData('heads');

    expect($heads['days'][$tuesday]['planned'][21])->toBe(1)
        ->and($heads['days'][$tuesday]['planned'][23])->toBe(1)
        // Midnight is not the Tuesday's, whatever the shift's business_date says.
        ->and($heads['days'][$tuesday]['planned'][0])->toBe(0)
        ->and($heads['days'][$wednesday]['planned'][0])->toBe(1)
        ->and($heads['days'][$wednesday]['planned'][1])->toBe(0);
});

it('counts a punch that clocked in and out inside the same minute', function () {
    // No hours, but somebody was here. Dropping a zero-length interval would
    // draw an empty store that had a person standing in it.
    $yesterday = CarbonImmutable::parse($this->today)->subDay()->toDateString();

    clockIn($yesterday, '13:00', '13:00');

    $day = headsWeek($yesterday, 'actual')->assertOk()->viewData('heads')['days'][$yesterday];

    expect($day['actual'][13])->toBe(1)
        ->and($day['actual'][14])->toBe(0);
});

// ── it is ours, not the warehouse's ─────────────────────────────────────

it('keeps the heads on the grid when the warehouse cannot be reached', function () {
    // THE REASON THIS ROW NO LONGER DISAPPEARS WITH THE SALES. The money is read
    // from another service and can be unavailable on an ordinary Tuesday; the
    // heads are counted from the shifts and punches already on this page, so
    // they cost no request and cannot fail. Losing them to somebody else's
    // outage was the old behaviour and it took the useful half with it.
    config()->set('lc_data.enabled', true);
    config()->set('lc_data.base_uri', 'https://warehouse.test/api');
    Http::fake(['warehouse.test/*' => Http::response('nope', 500)]);

    rosterShift($this->today, '17:00', '21:00');

    headsWeek($this->today, 'planned')
        ->assertOk()
        ->assertSee('Hourly sales are off the grid')
        // The row is still there, and headed for what is left in it.
        ->assertSee('most on at once')
        // ...and it does not claim to hold a sales figure it never got.
        ->assertDontSee('royalty obligation');
});

it('marks an hour that came up short, and leaves an hour that has not happened alone', function () {
    // The two look identical in the numbers — two planned, nobody clocked in —
    // and only one of them is a hole. Amber on next week's evenings would paint
    // half of every forward rota as a problem, which is how a warning colour
    // stops being read at all.
    $lastWeek = CarbonImmutable::parse($this->today)->subWeek()->toDateString();
    $nextWeek = CarbonImmutable::parse($this->today)->addWeek()->toDateString();

    foreach ([$lastWeek, $nextWeek] as $date) {
        rosterShift($date, '17:00', '21:00');
        rosterShift($date, '17:00', '21:00', employeeId: $this->other->id);
    }

    // A week that has been, worked by one of the two rostered for it.
    clockIn($lastWeek, '17:00', '21:00');

    headsWeek($lastWeek, 'both')->assertOk()
        ->assertSee('class="hc short"', false)
        ->assertSee('short of plan');

    headsWeek($nextWeek, 'both')->assertOk()
        ->assertDontSee('class="hc short"', false)
        ->assertDontSee('short of plan');
});

it('draws no hour row at all when there is neither a sale nor a soul', function () {
    // Fourteen rows of zeroes above an empty rota, on a page whose sales
    // integration is switched off, is a row that says nothing at some length.
    headsWeek($this->today, 'planned')
        ->assertOk()
        ->assertDontSee('most on at once')
        ->assertDontSee('The top row is by the hour');
});

it('draws the hour row for people alone, with no sales in it', function () {
    rosterShift($this->today, '17:00', '21:00');

    headsWeek($this->today, 'planned')
        ->assertOk()
        ->assertSee('most on at once')
        ->assertSee('The top row is by the hour')
        // The heading names what is actually in the row. "Sales · heads" over a
        // column with no sales in it would be a promise the row does not keep.
        ->assertSee('Heads')
        ->assertDontSee('royalty obligation');
});
