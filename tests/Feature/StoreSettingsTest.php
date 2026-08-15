<?php

use App\Models\Position;
use App\Models\StoreSetting;
use App\Services\Scheduling\StoreSettingService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Per-store settings, and the positions vocabulary
|--------------------------------------------------------------------------
|
| store_settings was seeder-only, which meant a store arriving from auth could
| not be configured without a deploy. timezone is the column that matters: it
| turns a UTC start_at into a business_date, so it decides which calendar day
| every shift is filed under.
|
| Two things these pin that are easy to get wrong:
|
|   BusinessDay memoises store_id => timezone in a private STATIC array, so a
|   write that does not flush it leaves the process resolving dates against the
|   old zone.
|
|   A store with no row must still answer — with the defaults every reader falls
|   back to — or a settings screen cannot tell "never configured" from
|   "configured as the default".
|
*/

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->settings = app(StoreSettingService::class);

    signIn();
});

// ── reading ─────────────────────────────────────────────────────────────

it('answers for a store that has never been configured', function () {
    StoreSetting::query()->where('store_id', DemoSeeder::STORE_ID)->delete();
    BusinessDay::flushTimezoneCache();

    $this->getJson(route('api.stores.settings.show', ['store' => DemoSeeder::STORE_ID]))
        ->assertOk()
        ->assertJsonPath('data.timezone', BusinessDay::DEFAULT_TIMEZONE)
        // The half a settings screen needs: these are defaults, not a choice
        // somebody made.
        ->assertJsonPath('data.configured', false);
});

it('does not create a row just for looking', function () {
    StoreSetting::query()->where('store_id', DemoSeeder::STORE_ID)->delete();

    $this->settings->forStore(DemoSeeder::STORE_ID);

    expect(StoreSetting::query()->where('store_id', DemoSeeder::STORE_ID)->exists())->toBeFalse();
});

// ── writing ─────────────────────────────────────────────────────────────

it('creates the row on the first save', function () {
    StoreSetting::query()->where('store_id', DemoSeeder::STORE_ID)->delete();

    // 201, not 200: the row did not exist and this PUT made it. A second PUT
    // to the same store is a 200 — see the test below.
    $this->putJson(route('api.stores.settings.update', ['store' => DemoSeeder::STORE_ID]), [
        'timezone' => 'America/Phoenix',
    ])->assertCreated()
        ->assertJsonPath('data.configured', true);

    expect(StoreSetting::query()->where('store_id', DemoSeeder::STORE_ID)->value('timezone'))
        ->toBe('America/Phoenix');
});

it('answers 200 when the row already existed', function () {
    $this->putJson(route('api.stores.settings.update', ['store' => DemoSeeder::STORE_ID]), [
        'timezone' => 'America/Phoenix',
    ])->assertOk();
});

it('flushes the static timezone cache, so the next read is the new zone', function () {
    $bd = app(BusinessDay::class);

    // Warm the static cache with the old value first — without the flush this
    // is what every later business_date in the process would be computed from.
    expect($bd->timezoneFor(DemoSeeder::STORE_ID))->toBe('America/New_York');

    $this->settings->update(DemoSeeder::STORE_ID, ['timezone' => 'America/Phoenix']);

    expect($bd->timezoneFor(DemoSeeder::STORE_ID))->toBe('America/Phoenix');
});

it('changes which calendar day a late instant belongs to', function () {
    $bd = app(BusinessDay::class);

    // 03:30 UTC — the previous evening in New York, still the previous day in
    // Phoenix too, but the offsets differ and that is the whole point of the
    // column.
    $instant = Carbon\CarbonImmutable::parse('2026-08-11T03:30:00Z');
    $before = $bd->businessDate(DemoSeeder::STORE_ID, $instant);

    $this->settings->update(DemoSeeder::STORE_ID, ['timezone' => 'Australia/Sydney']);

    expect($bd->businessDate(DemoSeeder::STORE_ID, $instant))->not->toBe($before);
});

it('refuses a timezone this server does not recognise', function () {
    $this->putJson(route('api.stores.settings.update', ['store' => DemoSeeder::STORE_ID]), [
        'timezone' => 'Middle/Earth',
    ])->assertStatus(422);

    expect(app(BusinessDay::class)->timezoneFor(DemoSeeder::STORE_ID))->toBe('America/New_York');
});

it('does not blank the fields a partial write left out', function () {
    $this->settings->update(DemoSeeder::STORE_ID, [
        'timezone' => 'America/Chicago',
        'publish_lead_days' => 21,
    ]);

    $this->putJson(route('api.stores.settings.update', ['store' => DemoSeeder::STORE_ID]), [
        'publish_lead_days' => 30,
    ])->assertOk();

    $row = StoreSetting::query()->where('store_id', DemoSeeder::STORE_ID)->firstOrFail();

    expect((int) $row->publish_lead_days)->toBe(30)
        ->and($row->timezone)->toBe('America/Chicago');
});

it('has no delete route: dropping the row would silently move the store to the default zone', function () {
    expect(collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->contains(fn ($route): bool => in_array('DELETE', $route->methods(), true)
            && str_contains($route->uri(), 'settings')))
        ->toBeFalse();
});

// ── the console screen ──────────────────────────────────────────────────

it('renders the settings screen and saves from it', function () {
    $this->get(route('board.settings', ['store' => DemoSeeder::STORE_ID]))
        ->assertOk()
        ->assertSee('America/New_York');

    $this->put(route('board.settings.update'), [
        'store_id' => DemoSeeder::STORE_ID,
        'timezone' => 'America/Denver',
        'publish_lead_days' => 7,
    ])->assertRedirect();

    expect(StoreSetting::query()->where('store_id', DemoSeeder::STORE_ID)->value('timezone'))
        ->toBe('America/Denver');
});

it('says out loud when the timezone moved', function () {
    $this->put(route('board.settings.update'), [
        'store_id' => DemoSeeder::STORE_ID,
        'timezone' => 'America/Denver',
        'publish_lead_days' => 14,
    ])->assertSessionHas('ok', fn (string $message): bool =>
        // Long-running workers hold the old zone in a static for the life of
        // the process, and nothing this request does can reach them.
        str_contains($message, 'queue workers'));
});

// ── positions ───────────────────────────────────────────────────────────

it('lists the positions a shift can be rostered as', function () {
    $this->getJson(route('api.positions.index'))
        ->assertOk()
        ->assertJsonPath('meta.count', Position::query()->count())
        ->assertJsonStructure(['data' => [['id', 'label', 'description']]]);
});

it('exposes no way to write a position, because it is a projection', function () {
    // Rows here are rebuilt from hiring.v1.employee.* payloads; a write would be
    // erased by the next replay.
    expect(collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->contains(fn ($route): bool => str_contains($route->uri(), 'positions')
            && array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== []))
        ->toBeFalse();
});
