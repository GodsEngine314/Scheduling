<?php

use App\Models\WorkSegment;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The actual-hours tab fetches the week it is showing
|--------------------------------------------------------------------------
|
| The defect this pins: the tab rendered whatever had already been pulled, and
| nothing pulled unless somebody found the button and pressed it. A store nobody
| had pressed it for showed an empty grid indistinguishable from "nobody worked",
| while the punches sat in TCP.
|
| The cost of fixing that badly is a vendor round trip on every render — every
| approve and correction on this tab redirects back here. So the pull is keyed on
| store AND week, recorded BEFORE the call, and can never break the page.
|
*/

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    // syncRange() names the people rather than the store — GET /worksegments has
    // no location filter — so without a TCP id on somebody it returns
    // store_has_no_tcp_employees and never asks for punches at all.
    App\Models\Employee::query()
        ->where('primary_store_id', DemoSeeder::STORE_ID)
        ->get()
        ->each(fn ($employee, $i) => $employee->forceFill([
            'tcp_employee_id' => (string) (55500 + $i),
        ])->save());

    $this->monday = now()->parse('2026-08-10')->toDateString();

    signIn();
});

/**
 * Requests for PUNCHES, not for the roster.
 *
 * syncRange() asks GET /employees first to turn a store into a list of people,
 * so counting every recorded request would count that too — and it is cached
 * per store, which makes the raw total depend on call order.
 */
function punchRequests(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'worksegments'))
        ->count();
}

/**
 * One TCP punch, in the shape GET /worksegments returns.
 */
function tcpPunch(string $id, int $tcpEmployeeId, string $in, string $out): array
{
    return [
        'id' => $id,
        'employeeId' => (string) $tcpEmployeeId,
        'timeIn' => $in,
        'timeOut' => $out,
        'locationId' => '9830400',
    ];
}

function fakePunches(array $punches): void
{
    Http::fake(['*' => Http::response(['data' => $punches], 200)]);
}

/** The actual tab for a store and week. */
function actualWeek(int $storeId, string $week)
{
    return test()->get(route('board.week', [
        'store' => $storeId, 'week' => $week, 'view' => 'actual',
    ]));
}

it('pulls the week from TCP when the actual tab first lands on it', function () {
    fakePunches([]);

    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();

    // The whole week in one request, not one call per day.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'worksegments')
            && str_contains($request->url(), '2026-08-10')
            && str_contains($request->url(), '2026-08-16');
    });
});

it('does not pull again for the same store and week', function () {
    fakePunches([]);

    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();
    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();
    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();

    // Every approval and correction on this tab redirects back here. Without
    // this, each one would wait on TCP.
    expect(punchRequests())->toBe(1);
});

it('pulls again when the week changes', function () {
    fakePunches([]);

    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();
    actualWeek(DemoSeeder::STORE_ID, '2026-08-17')->assertOk();

    expect(punchRequests())->toBe(2);
});

it('does not pull for the planned tab', function () {
    Http::fake();

    test()->get(route('board.week', [
        'store' => DemoSeeder::STORE_ID, 'week' => $this->monday,
    ]))->assertOk();

    // Planned shifts are ours. Nothing about that screen is TCP's business.
    Http::assertNothingSent();
});

it('renders the punches it just pulled, not the ones it had', function () {
    $employee = App\Models\Employee::query()
        ->where('primary_store_id', DemoSeeder::STORE_ID)
        ->whereNotNull('tcp_employee_id')
        ->first();

    // DemoSeeder's people carry no TCP id, so give one to the person the punch
    // is for — otherwise it cannot be attributed and is skipped.
    $employee ??= tap(
        App\Models\Employee::query()->where('primary_store_id', DemoSeeder::STORE_ID)->firstOrFail(),
        fn ($e) => $e->forceFill(['tcp_employee_id' => '55501'])->save()
    );

    fakePunches([
        tcpPunch('seg-1', (int) $employee->tcp_employee_id, '2026-08-11T13:00:00', '2026-08-11T21:00:00'),
    ]);

    expect(WorkSegment::query()->where('tcp_segment_id', 'seg-1')->exists())->toBeFalse();

    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();

    // The pull runs BEFORE the query, or the first render of a week shows the
    // punches it had rather than the ones it just fetched.
    expect(WorkSegment::query()->where('tcp_segment_id', 'seg-1')->exists())->toBeTrue();
});

it('still renders the grid when TCP is down', function () {
    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    // A convenience that fails is a message, never a broken screen.
    actualWeek(DemoSeeder::STORE_ID, $this->monday)
        ->assertOk()
        ->assertSee('Actual hours', false);
});

it('does not retry a failed week on every render', function () {
    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();
    $afterFirst = count(Http::recorded());

    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();

    // Recorded before the call, so a store whose pull fails does not spend the
    // retry budget again on every subsequent render of the same grid.
    expect(count(Http::recorded()))->toBe($afterFirst);
});

it('makes one request for the week, not one per day', function () {
    fakePunches([]);

    actualWeek(DemoSeeder::STORE_ID, $this->monday)->assertOk();

    // The filter takes a range, and a week's employee list is the same list
    // seven times over.
    expect(punchRequests())->toBe(1);
});
