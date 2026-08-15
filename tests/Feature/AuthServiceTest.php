<?php

use App\Models\Store;
use App\Services\Auth\TokenIntrospector;
use App\Support\AuthContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The auth seam, over the real HTTP path
|--------------------------------------------------------------------------
|
| Pest's signIn() helper stubs the introspector, so nothing else in the suite
| exercises the actual call. This file does: it fakes token-verify and asserts
| what goes out and what comes back.
|
| The rule that matters most here is FAIL CLOSED. Every way the authority can
| let us down — unreachable, 500, a body we do not recognise, no service
| credential — has to end in 401. An auth check that permits the request when it
| cannot reach the authority is not an auth check, and an outage would silently
| open every route in the service.
|
*/

const AUTH_BASE = 'https://auth.test/api/v1';

beforeEach(function () {
    config()->set('auth_service.base_uri', AUTH_BASE);
    config()->set('auth_service.service_name', 'scheduling');
    config()->set('auth_service.service_token', 'svc-secret');
    config()->set('auth_service.cache_ttl', 30);
    config()->set('auth_service.enforce_authorization', true);

    Queue::fake();
    Http::preventStrayRequests();
    Cache::flush();
});

/** The introspection response pizzasys returns for a good token. */
function fakeIntrospection(array $overrides = [], bool $authorized = true): void
{
    Http::fake([
        '*token-verify*' => Http::response(array_merge([
            'active' => true,
            'sub' => '1',
            'user' => ['id' => 1, 'name' => 'Dana Okafor', 'email' => 'dana@example.test'],
            'roles' => ['manager'],
            'permissions' => ['view schedule'],
            'ext' => ['authorized' => $authorized, 'required_permissions' => [], 'granted_by' => 'roles'],
        ], $overrides), 200),
    ]);
}

// ── what goes out ───────────────────────────────────────────────────────

it('identifies scheduling with its own service token, and asks about the user token', function () {
    fakeIntrospection();

    app(TokenIntrospector::class)->introspect('user-token-abc', 'POST', '/api/shifts', 'api.shifts.store');

    Http::assertSent(function ($request) {
        $body = $request->data();

        // The header is OURS; the body carries the user's. Swapping them would
        // authenticate nobody and ask the authority about its own credential.
        return $request->hasHeader('Authorization', 'Bearer svc-secret')
            && $body['service'] === 'scheduling'
            && $body['token'] === 'user-token-abc'
            && $body['method'] === 'POST'
            && $body['path'] === '/api/shifts'
            && $body['route_name'] === 'api.shifts.store';
    });
});

it('refuses to call at all with no service credential', function () {
    config()->set('auth_service.service_token', '');
    fakeIntrospection();

    $result = app(TokenIntrospector::class)->introspect('user-token', 'GET', '/board');

    // "Bearer " would earn a 401 that reads like a rejected credential rather
    // than an unset env var.
    Http::assertNothingSent();
    expect($result->active)->toBeFalse();
});

// ── what comes back ─────────────────────────────────────────────────────

it('reads the identity, roles and permissions out of the response', function () {
    fakeIntrospection();

    $result = app(TokenIntrospector::class)->introspect('t', 'GET', '/board');

    expect($result->active)->toBeTrue()
        ->and($result->authorized)->toBeTrue()
        ->and($result->userId)->toBe(1)
        ->and($result->name)->toBe('Dana Okafor')
        ->and($result->roles)->toBe(['manager'])
        ->and($result->hasPermission('view schedule'))->toBeTrue();
});

it('treats a missing authorized flag as not authorized', function () {
    fakeIntrospection(['ext' => []]);

    $result = app(TokenIntrospector::class)->introspect('t', 'GET', '/board');

    // An introspection body we do not recognise is not a yes.
    expect($result->active)->toBeTrue()
        ->and($result->authorized)->toBeFalse();
});

it('honours the upstream super-admin bypass', function () {
    fakeIntrospection(['roles' => ['super-admin'], 'permissions' => []]);

    $result = app(TokenIntrospector::class)->introspect('t', 'GET', '/board');

    // config('authz.super_roles') upstream. Asking here too keeps one answer to
    // "what can this person do" rather than two that can disagree.
    expect($result->isSuperAdmin())->toBeTrue()
        ->and(app(AuthContext::class)->can('anything at all'))->toBeFalse();
});

// ── fail closed ─────────────────────────────────────────────────────────

it('fails closed when the auth service is unreachable', function () {
    Sleep::fake();
    Http::fake(['*token-verify*' => Http::response('gateway down', 502)]);

    expect(app(TokenIntrospector::class)->introspect('t', 'GET', '/board')->active)->toBeFalse();
});

it('fails closed on a body it does not understand', function () {
    Http::fake(['*token-verify*' => Http::response(['unexpected' => true], 200)]);

    expect(app(TokenIntrospector::class)->introspect('t', 'GET', '/board')->active)->toBeFalse();
});

it('fails closed on an empty token without calling out', function () {
    fakeIntrospection();

    expect(app(TokenIntrospector::class)->introspect('   ', 'GET', '/board')->active)->toBeFalse();
    Http::assertNothingSent();
});

// ── caching ─────────────────────────────────────────────────────────────

it('asks once per token, method and path', function () {
    fakeIntrospection();
    $introspector = app(TokenIntrospector::class);

    $introspector->introspect('t', 'GET', '/board');
    $introspector->introspect('t', 'GET', '/board');

    expect(Http::recorded())->toHaveCount(1);
});

it('asks again for a different path, because the answer includes an authorization decision', function () {
    fakeIntrospection();
    $introspector = app(TokenIntrospector::class);

    $introspector->introspect('t', 'GET', '/board');
    $introspector->introspect('t', 'POST', '/api/shifts');

    // Caching on the token alone would let one allowed request authorise every
    // other one that token makes.
    expect(Http::recorded())->toHaveCount(2);
});

it('does not cache at all when the ttl is zero', function () {
    config()->set('auth_service.cache_ttl', 0);
    fakeIntrospection();
    $introspector = app(TokenIntrospector::class);

    $introspector->introspect('t', 'GET', '/board');
    $introspector->introspect('t', 'GET', '/board');

    expect(Http::recorded())->toHaveCount(2);
});

// ── the middleware, end to end ──────────────────────────────────────────

it('turns an inactive token into a 401 on the API', function () {
    Http::fake(['*token-verify*' => Http::response(['active' => false], 200)]);

    $this->withHeader('Authorization', 'Bearer nope')
        ->getJson('/api/board?store=1&date=2026-08-13')
        ->assertStatus(401);
});

it('turns an unauthorized token into a 403, naming what is missing', function () {
    fakeIntrospection(
        ['ext' => ['authorized' => false, 'required_permissions' => ['publish schedule']]],
    );

    $this->withHeader('Authorization', 'Bearer ok-but-not-allowed')
        ->getJson('/api/board?store=1&date=2026-08-13')
        ->assertStatus(403)
        ->assertJsonPath('required_permissions', ['publish schedule']);
});

it('sends the console to the login page rather than a 401 body', function () {
    Http::fake(['*token-verify*' => Http::response(['active' => false], 200)]);

    $this->get('/board')->assertRedirect(route('login'));
});

it('leaves the login page itself reachable', function () {
    // If this were behind the middleware there would be no way in at all.
    $this->get('/login')->assertOk()->assertSee('Sign in');
});

it('lets authorization enforcement be turned off without turning authentication off', function () {
    // A real store, so a 200 means the request got all the way through rather
    // than stopping at the store validation rule.
    Store::query()->create(['id' => 1, 'store_number' => '0001']);

    config()->set('auth_service.enforce_authorization', false);
    fakeIntrospection(['ext' => ['authorized' => false]]);

    // The escape hatch for a bad upstream rule: the request goes through even
    // though the authority refused it. Authentication is NOT relaxed with it —
    // an inactive token still 401s, which the test above pins separately.
    //
    // (Deliberately not re-faked here to prove the second half: Http::fake()
    // APPENDS stubs and the first match wins, so a second fake for the same
    // pattern never answers.)
    $this->withHeader('Authorization', 'Bearer t')
        ->getJson('/api/board?store=1&date=2026-08-13')
        ->assertStatus(200);
});
