<?php

use App\DataTransferObjects\EmployeeFilter;
use App\Exceptions\IntegrationException;
use App\Support\Integrations\Humanity\HumanityClient;
use App\Support\Integrations\Tcp\TcpClient;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| How a token reaches the vendor
|--------------------------------------------------------------------------
|
| Two independent questions, and conflating them is how an hour disappears:
|
|   MODE      where the token comes from — 'oauth' exchanges credentials for a
|             short-lived one, 'static' takes it straight from the env.
|   TRANSPORT how it rides on the request — an Authorization header, or a query
|             parameter. Humanity's own examples use ?_token=.
|
| Only 'oauth' gets the 401-refresh repair in AbstractApiClient, so a static
| token that expires fails every call until somebody edits the env.
|
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

// ── TCP ─────────────────────────────────────────────────────────────────

it('sends a TCP bearer token from the env without a token call', function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tcp-secret');

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    app(TcpClient::class)->employees(new EmployeeFilter(locations: ['9830400']));

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tcp-secret'));

    // Static means static: no /token round trip on the way.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/token'));
});

it('refuses a static TCP mode with no token rather than sending "Bearer "', function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', '');

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    // An empty header is a 401 that reads like a bad credential instead of
    // what it is — a missing line in the env.
    expect(fn () => app(TcpClient::class)->employees(new EmployeeFilter))
        ->toThrow(IntegrationException::class);

    Http::assertNothingSent();
});

it('honours a non-default TCP header and prefix', function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tcp-secret');
    config()->set('tcp.auth_header', 'X-Api-Key');
    config()->set('tcp.auth_prefix', '');

    Http::fake(['*' => Http::response(['data' => []], 200)]);

    app(TcpClient::class)->employees(new EmployeeFilter);

    // trim() around the prefix is why an empty one yields a bare token rather
    // than a leading space.
    Http::assertSent(fn ($r) => $r->hasHeader('X-Api-Key', 'tcp-secret'));
});

// ── Humanity ────────────────────────────────────────────────────────────

it('sends a Humanity bearer token from the env without a token call', function () {
    config()->set('humanity.auth_mode', 'static');
    config()->set('humanity.static_token', 'humanity-secret');
    config()->set('humanity.auth_transport', 'bearer');

    Http::fake(['*' => Http::response(['id' => 'HS-1'], 200)]);

    app(HumanityClient::class)->createShift(['start_time' => '2026-08-13 09:00:00']);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer humanity-secret'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'oauth2/token'));
});

it('can carry a static Humanity token as the _token query parameter', function () {
    config()->set('humanity.auth_mode', 'static');
    config()->set('humanity.static_token', 'humanity-secret');
    config()->set('humanity.auth_transport', '_token');

    Http::fake(['*' => Http::response(['id' => 'HS-1'], 200)]);

    app(HumanityClient::class)->createShift(['start_time' => '2026-08-13 09:00:00']);

    // Mode and transport are independent: a token from the env can still ride
    // the way Humanity's own examples send it.
    Http::assertSent(function ($r) {
        $query = [];
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

        return ($query['_token'] ?? null) === 'humanity-secret';
    });
});

it('refuses a static Humanity mode with no token', function () {
    config()->set('humanity.auth_mode', 'static');
    config()->set('humanity.static_token', '');

    Http::fake(['*' => Http::response([], 200)]);

    expect(fn () => app(HumanityClient::class)->createShift([]))
        ->toThrow(IntegrationException::class);

    Http::assertNothingSent();
});

it('rejects an unknown Humanity auth mode instead of guessing', function () {
    config()->set('humanity.auth_mode', 'basic');

    Http::fake(['*' => Http::response([], 200)]);

    expect(fn () => app(HumanityClient::class)->createShift([]))
        ->toThrow(IntegrationException::class);

    Http::assertNothingSent();
});

it('still exchanges credentials when Humanity is left on oauth', function () {
    config()->set('humanity.auth_mode', 'oauth');
    config()->set('humanity.oauth.client_id', 'cid');
    config()->set('humanity.oauth.client_secret', 'secret');
    config()->set('humanity.oauth.username', 'user');
    config()->set('humanity.oauth.password', 'pass');
    config()->set('humanity.auth_transport', 'bearer');

    // Registration order matters: the token stub has to be matched before the
    // catch-all, or the client reads a shift body looking for an access_token.
    Http::fake([
        '*oauth2/token*' => Http::response(['access_token' => 'fetched-token', 'expires_in' => 3600], 200),
        '*' => Http::response(['id' => 'HS-1'], 200),
    ]);

    app(HumanityClient::class)->createShift(['start_time' => '2026-08-13 09:00:00']);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2/token'));
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer fetched-token'));
});
