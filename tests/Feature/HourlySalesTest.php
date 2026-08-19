<?php

use App\Models\Store;
use App\Services\Scheduling\HourlySalesReader;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Hourly sales on the week grid
|--------------------------------------------------------------------------
|
| The column that says what the store was DOING while those people worked.
| Read live from LC_PIZZA_DATA, stored nowhere here, and — the part most of
| these tests are about — never able to break the page it sits on.
|
| The warehouse is a peer service behind the same auth server, so two things
| are pinned hard: the caller's own token is what goes out with the request,
| and every failure degrades to a grid without a sales row rather than to an
| error page.
|
*/

beforeEach(function () {
    Queue::fake();

    // Nothing in this file may touch the network. The TCP pull the week view
    // does on its way past is not what these tests are about; it strays, the
    // controller catches it, and the grid renders — which is exactly what
    // happens in production when TCP is unreachable.
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->bd = app(BusinessDay::class);
    $this->today = $this->bd->toLocal(DemoSeeder::STORE_ID, now())->toDateString();
    $this->week = CarbonImmutable::parse($this->today)->startOfWeek(CarbonInterface::TUESDAY);

    // phpunit.xml turns the integration off for the rest of the suite. Every
    // test here is about the feature itself, so it goes back on.
    config()->set('lc_data.enabled', true);
    config()->set('lc_data.base_uri', 'https://warehouse.test/api');

    signIn();
});

/** Stub the warehouse with $amounts keyed by hour, for one business date. */
function fakeWarehouse(string $date, array $amounts = [], ?float $dayTotal = null): void
{
    Http::fake(['warehouse.test/*' => Http::response([
        'filtering' => ['store' => '4821', 'from' => $date, 'to' => $date, 'metric' => 'royalty_obligation'],
        'days' => [
            $date => [
                'by_hour' => $amounts,
                'day_total' => $dayTotal ?? array_sum($amounts),
            ],
        ],
    ])]);
}

/**
 * How many times the warehouse was asked.
 *
 * Counted by URL rather than with assertSentCount, because the week view also
 * reaches for TCP on its way past and that call has nothing to say about this
 * feature.
 */
function warehouseCalls(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'hourly-sales'))->count();
}

it('puts every hour from 10AM to 11PM at the top of each day column', function () {
    // The window is 10 → 23 INCLUSIVE: fourteen buckets, ending with the 23:00
    // one. Midnight is where the window stops, not a bucket inside it — an
    // order at 00:30 belongs to the next business date and the warehouse dates
    // it that way.
    fakeWarehouse($this->today);

    $response = $this->get('/board/week')->assertOk();

    foreach (['10 AM', '11 AM', '12 PM', '5 PM', '11 PM'] as $label) {
        $response->assertSee($label);
    }

    // The span is labelled to midnight even though the last ROW is 11 PM.
    $response->assertSee('10 AM–12 AM');

    // ...and 12 AM is never a row of its own.
    expect(substr_count($response->getContent(), '>12 AM<'))->toBe(0);
});

it('shows the royalty obligation next to the hour it was taken in', function () {
    fakeWarehouse($this->today, [
        '10' => 84.5,
        '11' => 412.25,
        '17' => 968.4,
        '23' => 12.0,
    ]);

    $this->get('/board/week')
        ->assertOk()
        // Whole dollars in the rows. Fourteen of them stacked in a column this
        // narrow, and the cents are noise in front of the only question the
        // column is asked: which hours are the big ones.
        ->assertSee('$412')
        ->assertSee('$968')
        // The window total keeps its cents — that one is a figure somebody
        // checks against another report.
        ->assertSee('$1,477.15');
});

it('sends the caller’s own token to the warehouse, not a service credential', function () {
    // THE SECURITY MODEL OF THIS FEATURE, IN ONE ASSERTION. Scheduling holds no
    // key to the estate's revenue: it forwards the token the request arrived
    // with, so the warehouse makes the same store-scope decision it would have
    // made if the manager had called it directly. A service token here would be
    // a standing key to every store's takings, held by a service that has no
    // business holding one.
    fakeWarehouse($this->today);

    $this->get('/board/week')->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'hourly-sales')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('asks for the whole week in one request', function () {
    // Seven round trips to draw one page is how a board render becomes a
    // timeout the first time the warehouse is slow.
    fakeWarehouse($this->today);

    $this->get('/board/week')->assertOk();

    expect(warehouseCalls())->toBe(1);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reports/hourly-sales/4821/'.$this->week->toDateString())
        && str_contains($request->url(), 'to='.$this->week->addDays(6)->toDateString()));
});

it('reads a finished week from cache the second time', function () {
    // Paging back and forth through the grid is the normal way this page is
    // used. A week whose days have all ended cannot change again except by a
    // re-import, so looking at it twice must not cost two round trips.
    fakeWarehouse($this->today, ['17' => 500.0]);

    $past = $this->week->subWeeks(4)->toDateString();

    $this->get('/board/week?week='.$past)->assertOk();
    $this->get('/board/week?week='.$past)->assertOk();

    expect(warehouseCalls())->toBe(1);
});

it('re-asks for a week containing today', function () {
    // Today's figures are still being made. Caching them for hours would show a
    // manager a lunch rush that had already ended.
    config()->set('lc_data.cache.open_day_ttl', 0);
    fakeWarehouse($this->today, ['12' => 300.0]);

    $this->get('/board/week')->assertOk();
    $this->get('/board/week')->assertOk();

    expect(warehouseCalls())->toBe(2);
});

it('renders the grid without the sales row when the warehouse is down', function () {
    // THE POINT OF THE WHOLE DEGRADE PATH. This column is a convenience; the
    // schedule is not. A rota that would not render because a reporting service
    // was down would be a far worse bug than the one the column fixes.
    Http::fake(['warehouse.test/*' => Http::response('nope', 500)]);

    $this->get('/board/week')
        ->assertOk()
        ->assertSee('Ada Okafor')
        ->assertDontSee('royalty obligation');
});

it('says why the sales are missing rather than leaving a gap', function () {
    // A column that is simply absent reads as "this store has no sales", which
    // is a different and much more alarming statement than "nobody answered".
    Http::fake(['warehouse.test/*' => Http::response('nope', 500)]);

    $this->get('/board/week')
        ->assertOk()
        ->assertSee('Hourly sales are off the grid')
        ->assertSee('Sales could not be read from LC_PIZZA_DATA just now.');
});

it('asks for nothing at all when the integration is switched off', function () {
    fakeWarehouse($this->today, ['17' => 500.0]);
    config()->set('lc_data.enabled', false);

    $this->get('/board/week')
        ->assertOk()
        ->assertSee('Ada Okafor')
        ->assertDontSee('royalty obligation');

    expect(warehouseCalls())->toBe(0);
});

it('separates the displayed window from the day’s full total', function () {
    // A store that took money at 02:00 has a day total the visible column does
    // not add up to. Shown as its own line rather than folded into an hour it
    // did not happen in, or the arithmetic silently stops working and nobody
    // can tell why.
    fakeWarehouse($this->today, ['17' => 100.0], dayTotal: 175.5);

    $this->get('/board/week')
        ->assertOk()
        ->assertSee('+$75.50 outside');
});

it('draws an hour the warehouse did not mention as zero, not as missing', function () {
    // Absent from the response means the store took nothing that hour. Every
    // hour in the window gets a row either way — a gap in the list reads as a
    // gap in the DATA, which is a different claim entirely.
    Http::fake(['warehouse.test/*' => Http::response(['days' => []])]);

    $this->get('/board/week')
        ->assertOk()
        ->assertSee('10 AM')
        ->assertSee('$0');
});

it('clamps a window that would wrap past midnight', function () {
    // 22 → 2 would have to span two business dates, and a column headed one
    // date cannot show another date's hours without lying. Clamped to the
    // opening hour rather than wrapped.
    config()->set('lc_data.window.from_hour', 22);
    config()->set('lc_data.window.to_hour', 2);

    expect(app(HourlySalesReader::class)->window())->toBe([22]);
});

it('asks for a real roster store in the exact format the warehouse seeds', function () {
    // THE CROSS-REPO CONTRACT, and the only thing joining the two systems.
    // Scheduling sends store_number; LC_PIZZA_DATA looks it up as
    // franchise_store. There is no id mapping and no fuzzy match, so a
    // disagreement about the format is not an error anywhere — it is a column
    // of zeroes that reads as a quiet week.
    //
    // 03795-00001 is what StoreSeeder writes here and what
    // HourlyStoreSalesSeeder writes there. If either side ever changes shape,
    // this fails rather than the console quietly going blank.
    $store = Store::query()->where('store_number', '03795-00001')->firstOrFail();

    fakeWarehouse($this->today);

    $this->get('/board/week?store='.$store->id)->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reports/hourly-sales/03795-00001/'));
});

it('reads sales against the store number, never the scheduling store id', function () {
    // The warehouse has never heard of scheduling's store ids. Passing one
    // would return an empty result rather than an error, which is the worst
    // failure available here: a column of zeroes that reads as a quiet week.
    //
    // The demo store's id and number happen to be the same value, so this
    // needs a store where they differ to say anything at all.
    $store = new Store(['store_number' => 'LC-0099']);
    $store->id = 90210;
    $store->save();

    fakeWarehouse($this->today);

    $this->get('/board/week?store=90210')->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/hourly-sales/LC-0099/'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/hourly-sales/90210/'));
});

it('draws the column from the local generator when stubbing is on', function () {
    // The unblock. Reading real sales means MySQL, three warehouse databases,
    // a set of partitioned migrations and a reachable auth service; this makes
    // the feature workable without any of it.
    Http::preventStrayRequests();
    config()->set('lc_data.stub', true);

    $this->get('/board/week')
        ->assertOk()
        ->assertSee('royalty obligation')
        ->assertSee('10 AM')
        // Invented figures say so on the grid itself. Without this a screenshot
        // of made-up revenue is indistinguishable from the real thing.
        ->assertSee('SAMPLE')
        ->assertSee('generated locally — not real data');

    expect(warehouseCalls())->toBe(0);
});

it('never stubs outside local and testing', function () {
    // The check is in the reader, in code — a config value travels in a .env,
    // and a .env is the thing people copy to a server by accident.
    config()->set('lc_data.stub', true);
    app()->detectEnvironment(fn (): string => 'production');

    fakeWarehouse($this->today, ['17' => 500.0]);

    $this->get('/board/week')->assertOk()->assertDontSee('SAMPLE');

    // It went to the warehouse instead of inventing anything.
    expect(warehouseCalls())->toBe(1);
});

it('invents nothing for a day that has not happened yet', function () {
    // A store cannot have taken money tomorrow, and a column of figures on next
    // week's grid would mislead exactly the person building that week's rota.
    config()->set('lc_data.stub', true);
    Http::preventStrayRequests();

    $nextWeek = $this->week->addWeek()->toDateString();

    $response = $this->get('/board/week?week='.$nextWeek)->assertOk();

    // Every hour still gets a row; every one of them is zero.
    expect(substr_count($response->getContent(), 'sales-hours'))->toBeGreaterThan(0);
    $response->assertSee('$0');
});

it('names the dev bypass instead of blaming the warehouse', function () {
    // The commonest local setup. This used to report itself as a warehouse that
    // was merely unreachable, sending people to check a service that was fine.
    Http::preventStrayRequests();
    config()->set('lc_data.dev_token', null);

    // Sign in the way the console does under AUTH_SERVICE_DEV_BYPASS.
    $this->withHeader('Authorization', 'Bearer '.\App\Services\Auth\DevBypass::SENTINEL);

    $this->get('/board/week')
        ->assertOk()
        ->assertSee('dev bypass')
        ->assertSee('LC_DATA_STUB')
        ->assertDontSee('Sales could not be read from LC_PIZZA_DATA just now.');
});

it('forwards the dev token when the caller has none of their own', function () {
    config()->set('lc_data.dev_token', 'a-real-warehouse-token');
    fakeWarehouse($this->today, ['17' => 500.0]);

    $this->withHeader('Authorization', 'Bearer '.\App\Services\Auth\DevBypass::SENTINEL);

    $this->get('/board/week')->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'hourly-sales')
        && $request->hasHeader('Authorization', 'Bearer a-real-warehouse-token'));
});
