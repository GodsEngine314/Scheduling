<?php

use App\Services\Auth\DevBypass;
use App\Services\Auth\TokenIntrospector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The local development bypass
|--------------------------------------------------------------------------
|
| A hardcoded credential that grants super-admin. It exists so the console can
| be worked on without the auth service running, and the tests that matter most
| here are the ones proving it CANNOT do anything outside local/testing.
|
| Two independent conditions, and the environment one is not configurable:
| copying a .env to a server is exactly how a flag like this becomes a breach,
| so the flag alone is not allowed to be enough.
|
*/

beforeEach(function () {
    config()->set('auth_service.dev_bypass.enabled', true);
    config()->set('auth_service.dev_bypass.username', 'admin@admin.com');
    config()->set('auth_service.dev_bypass.password', 'admin');
    config()->set('auth_service.dev_bypass.user_id', null);

    Http::preventStrayRequests();
});

// ── it works, locally ───────────────────────────────────────────────────

it('signs in with admin@admin.com / admin without contacting the auth service', function () {
    $this->post('/login', ['email' => 'admin@admin.com', 'password' => 'admin'])
        ->assertRedirect(route('board'));

    // The whole point: no authority was reached.
    Http::assertNothingSent();

    // And the session it left behind opens a gated page.
    $this->get('/board')->assertOk();
});

it('carries super-admin, so every route is reachable', function () {
    $this->post('/login', ['email' => 'admin@admin.com', 'password' => 'admin'])->assertRedirect();

    $introspection = app(TokenIntrospector::class)->introspect(DevBypass::SENTINEL, 'GET', '/board');

    expect($introspection->active)->toBeTrue()
        ->and($introspection->authorized)->toBeTrue()
        ->and($introspection->isSuperAdmin())->toBeTrue();
});

it('says loudly on every page that nobody checked a credential', function () {
    $this->post('/login', ['email' => 'admin@admin.com', 'password' => 'admin'])->assertRedirect();

    // A bypassed session is identical to a real one everywhere else, which is
    // the design — so the banner is the only thing keeping the two apart.
    $this->get('/board')->assertOk()->assertSee('LOCAL DEVELOPMENT BYPASS');
});

it('offers the bypass on the login page when it is on', function () {
    $this->get('/login')->assertOk()->assertSee('LOCAL DEVELOPMENT BYPASS IS ON');
});

// ── it refuses everything else ──────────────────────────────────────────

it('refuses the wrong password', function () {
    // 'admin' is not an email either, so this falls through the bypass and is
    // then rejected by validation. Either way what matters is the next line.
    $this->post('/login', ['email' => 'admin@admin.com', 'password' => 'not-admin']);

    $this->get('/board')->assertRedirect(route('login'));
});

it('refuses a username that is not the configured one', function () {
    $this->post('/login', ['email' => 'someone.else@admin.com', 'password' => 'admin']);

    $this->get('/board')->assertRedirect(route('login'));
});

it('is off unless the flag is explicitly set', function () {
    config()->set('auth_service.dev_bypass.enabled', false);

    expect(app(DevBypass::class)->enabled())->toBeFalse()
        ->and(app(DevBypass::class)->matches('admin@admin.com', 'admin'))->toBeFalse();

    // And the sentinel stops meaning anything the moment it is off.
    expect(app(TokenIntrospector::class)->introspect(DevBypass::SENTINEL, 'GET', '/board')->active)
        ->toBeFalse();
});

it('REFUSES TO WORK IN PRODUCTION even with the flag on', function () {
    // The guard that matters. config says yes; the environment says no.
    app()->detectEnvironment(fn () => 'production');

    expect(app()->environment())->toBe('production')
        ->and(config('auth_service.dev_bypass.enabled'))->toBeTrue()
        ->and(app(DevBypass::class)->enabled())->toBeFalse()
        ->and(app(DevBypass::class)->matches('admin@admin.com', 'admin'))->toBeFalse()
        ->and(app(DevBypass::class)->isSentinel(DevBypass::SENTINEL))->toBeFalse();
});

it('will not let the sentinel authenticate a request in production', function () {
    app()->detectEnvironment(fn () => 'production');

    // Somebody who learned the sentinel from the repository gets nothing.
    expect(app(TokenIntrospector::class)->introspect(DevBypass::SENTINEL, 'GET', '/board')->active)
        ->toBeFalse();
});

it('does not look like a real token', function () {
    // Sanctum tokens are "{id}|{secret}". This must not be mistakable for one
    // in a log line or a session dump.
    expect(DevBypass::SENTINEL)->not->toContain('|');
});

// ── it does not disturb the real path ───────────────────────────────────

it('leaves a genuine token to the auth service even while enabled', function () {
    config()->set('auth_service.base_uri', 'https://auth.test/api/v1');
    config()->set('auth_service.service_token', 'svc');

    Http::fake(['*token-verify*' => Http::response(['active' => false], 200)]);

    app(TokenIntrospector::class)->introspect('2|a-real-looking-token', 'GET', '/board');

    // The bypass answers for its own sentinel and nothing else.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'token-verify'));
});
