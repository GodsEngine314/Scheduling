<?php

namespace App\Services\Auth;

use App\Support\Integrations\Auth\AuthServiceClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ask the auth service what a token means, without asking twice a second.
 *
 * IT FAILS CLOSED. Every failure path — an unreachable auth service, a 500, a
 * body in a shape we do not recognise, a missing service credential — returns
 * an INACTIVE result, which the middleware turns into a 401. That is the one
 * rule in this file that is not a trade-off: an auth check that permits the
 * request when it cannot reach the authority is not an auth check, and an
 * outage would silently open every route in the service.
 *
 * The cost is honest and worth stating: if the auth service is down, nobody can
 * use scheduling. That is the correct failure for an identity dependency.
 */
class TokenIntrospector
{
    public function __construct(
        private readonly AuthServiceClient $client,
        private readonly DevBypass $devBypass,
    ) {}

    public function introspect(string $userToken, string $method, string $path, ?string $routeName = null): TokenIntrospection
    {
        if (trim($userToken) === '') {
            return TokenIntrospection::inactive();
        }

        // Answered locally, and never cached — isSentinel() is already gated on
        // the environment, so there is nothing to save by remembering it. The
        // result flows on through the same middleware as a real introspection.
        if ($this->devBypass->isSentinel($userToken)) {
            return $this->devBypass->introspection();
        }

        $ttl = (int) config('auth_service.cache_ttl', 30);

        if ($ttl <= 0) {
            return $this->ask($userToken, $method, $path, $routeName);
        }

        // Keyed on the request as well as the token, because the answer includes
        // an authorization decision for THIS method and path. Caching on the
        // token alone would let one allowed request authorise every other.
        $key = 'auth:introspect:'.hash('sha256', implode('|', [
            $userToken, strtoupper($method), $path, (string) $routeName,
        ]));

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return TokenIntrospection::fromResponse($cached);
        }

        $result = $this->ask($userToken, $method, $path, $routeName);

        // Negatives are cached too, and for the same TTL. Without it an invalid
        // or expired token turns every retry into a round trip, which is a free
        // amplifier pointed at the authority. The cost is that a token stays
        // rejected for a few seconds after it becomes valid, and nothing makes
        // a token valid again — a new login issues a new one, under a new key.
        Cache::put($key, $result->raw === [] ? ['active' => false] : $result->raw, $ttl);

        return $result;
    }

    /** Forget one token's cached answers. Called on logout. */
    public function forget(string $userToken): void
    {
        // Only the exact per-request keys can be built, and there is no reliable
        // prefix delete across cache stores, so a logout leaves at most one
        // cache_ttl window of stale allows behind. Shorten cache_ttl if that
        // window matters more than the round trips it saves.
        Cache::forget('auth:introspect:'.hash('sha256', $userToken));
    }

    private function ask(string $userToken, string $method, string $path, ?string $routeName): TokenIntrospection
    {
        try {
            return TokenIntrospection::fromResponse(
                $this->client->verifyToken($userToken, $method, $path, $routeName)
            );
        } catch (Throwable) {
            // Deliberately swallowed rather than rethrown. AbstractApiClient has
            // already logged the call with its correlation id, and there is
            // nothing a caller can usefully do with the difference between "the
            // authority said no" and "the authority could not be reached" —
            // both mean this request does not proceed.
            return TokenIntrospection::inactive();
        }
    }
}
