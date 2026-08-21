<?php

namespace App\Support\Integrations;

use App\Exceptions\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches and caches OAuth access tokens, one cache entry per integration.
 *
 * Both vendors are configured with an oauth block in their own config file, so
 * the integration name ('tcp', 'humanity') doubles as the config key and as
 * the cache key. The two grants differ only in which form fields they need,
 * which is why there is one class here and not two: client_credentials sends
 * client id and secret, Humanity's password grant sends a username and
 * password as well, and everything else about the exchange is identical.
 *
 * Nothing here logs a credential or a token. The token endpoint is the one
 * request in the service whose body IS the secret, so its response body never
 * reaches an exception message either.
 */
class TokenProvider
{
    /**
     * Never cache for less than this. Guards against a vendor returning an
     * expires_in smaller than our refresh skew, which would otherwise compute
     * a negative TTL and hammer the token endpoint on every single call.
     */
    private const MINIMUM_TTL_SECONDS = 30;

    /**
     * GUESS: no live token response has been seen, so a response without an
     * expires_in is assumed to last an hour. If that is wrong the 401 retry
     * path in AbstractApiClient covers it — expensively, but correctly.
     */
    private const ASSUMED_LIFETIME_SECONDS = 3600;

    public function token(string $integration): string
    {
        $cached = Cache::get($this->cacheKey($integration));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $ttlSeconds] = $this->fetch($integration);

        Cache::put($this->cacheKey($integration), $token, $ttlSeconds);

        return $token;
    }

    /**
     * Drop the cached token. Called by the 401 path: the vendor has told us
     * the token is no good, and believing our own cache over that answer just
     * produces another 401.
     */
    public function forget(string $integration): void
    {
        Cache::forget($this->cacheKey($integration));
    }

    public function cacheKey(string $integration): string
    {
        return "integrations:{$integration}:access_token";
    }

    /**
     * @return array{0:string,1:int} the token and the seconds to cache it for
     */
    private function fetch(string $integration): array
    {
        $oauth = (array) config("{$integration}.oauth", []);
        $baseUri = rtrim((string) config("{$integration}.base_uri", ''), '/');
        $tokenPath = (string) ($oauth['token_path'] ?? '');

        if ($baseUri === '' || $tokenPath === '') {
            throw IntegrationException::configuration(
                $integration,
                "{$integration}.base_uri and {$integration}.oauth.token_path must both be set.",
            );
        }

        // An ABSOLUTE token_path wins over base_uri, because for both vendors
        // the token lives on a different host from the API:
        //
        //   TCP       auth.api.tcplusondemand.com/oauth2/token, not api.…/v1
        //   Humanity  www.humanity.com/oauth2/token.php, not …/api/v2
        //
        // Joining them would POST credentials at a path that does not exist and
        // read the 404 as a bad grant.
        $endpoint = Str::startsWith($tokenPath, ['http://', 'https://'])
            ? $tokenPath
            : $baseUri.'/'.ltrim($tokenPath, '/');
        $correlationId = (string) Str::uuid();
        $startedAt = microtime(true);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config("{$integration}.timeout", 30))
                ->withHeaders([AbstractApiClient::CORRELATION_HEADER => $correlationId])
                ->post($endpoint, $this->grantForm($integration, $oauth));
        } catch (ConnectionException $e) {
            $this->log($integration, $endpoint, null, $startedAt, $correlationId);

            throw IntegrationException::connectionFailure($integration, 'POST', $endpoint, $correlationId, $e);
        }

        $this->log($integration, $endpoint, $response->status(), $startedAt, $correlationId);

        if (! $response->successful()) {
            /**
             * A REJECTED CREDENTIAL IS NOT A BAD REQUEST, and the difference is
             * the whole message. This one travels all the way to
             * shifts.last_publish_error and onto the board, where a manager
             * reads it — so it has to say that the login was refused and that
             * nothing was sent, rather than naming an HTTP status on a URL they
             * have never heard of.
             *
             * 400 as well as 401/403: RFC 6749 has invalid_grant as a 400, and
             * Humanity answers 401. Either way it is the same problem.
             */
            if (in_array($response->status(), [400, 401, 403], true)) {
                throw IntegrationException::credentialsRejected(
                    $integration,
                    $endpoint,
                    $response->status(),
                    $correlationId,
                );
            }

            // No body excerpt: a token-endpoint error can quote the credentials
            // it just rejected.
            throw IntegrationException::fromResponse(
                $integration,
                'POST',
                $endpoint,
                $response->status(),
                $correlationId,
            );
        }

        $token = (string) ($response->json('access_token') ?? '');

        if ($token === '') {
            // A 200 with no token is a contract problem, not a blip. Retrying
            // it would just produce the same useless response.
            throw IntegrationException::guard(
                $integration,
                $endpoint,
                "{$integration} returned a successful token response with no access_token.",
            );
        }

        $expiresIn = (int) ($response->json('expires_in') ?? self::ASSUMED_LIFETIME_SECONDS);
        $skew = (int) ($oauth['refresh_skew_seconds'] ?? 60);

        return [$token, max(self::MINIMUM_TTL_SECONDS, $expiresIn - $skew)];
    }

    /**
     * Build the grant body from whatever the integration's oauth block holds.
     *
     * Tolerant by design: client_credentials (TCP) and password (Humanity)
     * differ only by two fields, so anything present is sent and anything
     * absent is omitted rather than sent empty.
     *
     * @param  array<string,mixed>  $oauth
     * @return array<string,string>
     */
    private function grantForm(string $integration, array $oauth): array
    {
        $grantType = (string) ($oauth['grant_type'] ?? 'client_credentials');

        foreach (['client_id', 'client_secret'] as $required) {
            if ((string) ($oauth[$required] ?? '') === '') {
                throw IntegrationException::configuration(
                    $integration,
                    "{$integration}.oauth.{$required} is not set.",
                );
            }
        }

        if ($grantType === 'password') {
            foreach (['username', 'password'] as $required) {
                if ((string) ($oauth[$required] ?? '') === '') {
                    throw IntegrationException::configuration(
                        $integration,
                        "{$integration}.oauth.{$required} is required for the password grant.",
                    );
                }
            }
        }

        $form = [
            'grant_type' => $grantType,
            'client_id' => (string) $oauth['client_id'],
            'client_secret' => (string) $oauth['client_secret'],
            'username' => (string) ($oauth['username'] ?? ''),
            'password' => (string) ($oauth['password'] ?? ''),

            // Listed among Humanity's token parameters and absent from TCP's.
            // Sent only when configured, for the same reason as the two above.
            'redirect_uri' => (string) ($oauth['redirect_uri'] ?? ''),

            // An empty scope means "send no scope", not "send scope=".
            'scope' => (string) ($oauth['scope'] ?? ''),
        ];

        return array_filter($form, static fn (string $value): bool => $value !== '');
    }

    /**
     * Field names only, never values — this request's body is a credential.
     */
    private function log(string $integration, string $endpoint, ?int $status, float $startedAt, string $correlationId): void
    {
        try {
            $context = [
                'integration' => $integration,
                'endpoint' => $endpoint,
                'method' => 'POST',
                'status' => $status,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'correlation_id' => $correlationId,
            ];

            $status !== null && $status < 400
                ? Log::info('integration.token', $context)
                : Log::warning('integration.token', $context);
        } catch (Throwable) {
            // Logging must never be the reason a token fetch fails.
            try {
                Log::warning('integration.token.log_failed', ['integration' => $integration]);
            } catch (Throwable) {
                // Nothing left to try.
            }
        }
    }
}
