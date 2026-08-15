<?php

namespace App\Services\Auth;

/**
 * A local sign-in that does not need the auth service running.
 *
 * WHY IT EXISTS. Scheduling fails closed: with no reachable authority nobody can
 * open the console, which is correct in production and miserable for working on
 * a Blade template. This lets a developer sign in with a fixed username and
 * password and get on with it.
 *
 * WHY IT IS GUARDED THE WAY IT IS. It is, plainly, a hardcoded credential that
 * grants super-admin. If it ever ran anywhere real, anyone who could reach the
 * console would own every store's schedule and every employee's pay rate. So two
 * independent things must both be true before it does anything:
 *
 *   1. AUTH_SERVICE_DEV_BYPASS is explicitly on. Off by default.
 *   2. The app is in the `local` or `testing` environment.
 *
 * The second is not configurable and is checked here rather than trusted to a
 * deployment leaving the flag alone. Copying a .env to a server is how this
 * class would otherwise become a breach, and a .env is exactly the file people
 * copy.
 *
 * IT ISSUES A SENTINEL, NOT A TOKEN. Signing in this way puts a known string in
 * the session where a real token would go. TokenIntrospector recognises it and
 * answers locally; every other part of the system — the middleware, AuthContext,
 * ActingUser, attribution — behaves exactly as it does for a real identity,
 * because it is the same code path. There is no second auth mode to keep in step.
 */
class DevBypass
{
    /**
     * What goes in the session instead of a Sanctum token.
     *
     * Deliberately not token-shaped: a real Sanctum token is "{id}|{secret}",
     * and this cannot be mistaken for one in a log or a session dump.
     */
    public const SENTINEL = 'dev-bypass-not-a-real-token';

    /**
     * Both conditions, and the environment one is not negotiable.
     */
    public function enabled(): bool
    {
        if (! (bool) config('auth_service.dev_bypass.enabled', false)) {
            return false;
        }

        // Even with the flag on. A config value is a thing someone copies by
        // accident; an environment is a thing someone chooses.
        return app()->environment(['local', 'testing']);
    }

    /** Does this pair match the configured development credential? */
    public function matches(string $username, string $password): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $expectedUser = (string) config('auth_service.dev_bypass.username', 'admin');
        $expectedPass = (string) config('auth_service.dev_bypass.password', 'admin');

        // hash_equals on both halves. The timing of a development login is not a
        // real threat, but writing the comparison the other way here is how the
        // habit gets copied into somewhere it does matter.
        return hash_equals($expectedUser, $username) && hash_equals($expectedPass, $password);
    }

    public function isSentinel(string $token): bool
    {
        return $this->enabled() && hash_equals(self::SENTINEL, $token);
    }

    /**
     * The identity a bypassed session carries.
     *
     * super-admin, because the point is to be able to exercise everything
     * without the authority present — and because that role already means
     * "bypass the rules" upstream, so nothing new is being invented here.
     */
    public function introspection(): TokenIntrospection
    {
        $userId = config('auth_service.dev_bypass.user_id');

        return new TokenIntrospection(
            active: true,
            authorized: true,
            // Attribution still needs a row in the users PROJECTION — these are
            // foreign keys. Null is fine and honest when it is not there; the
            // console says "not projected yet" rather than inventing anybody.
            userId: $userId === null ? null : (int) $userId,
            name: 'Local dev ('.config('auth_service.dev_bypass.username', 'admin').')',
            email: null,
            roles: ['super-admin'],
            permissions: [],
            raw: ['active' => true, 'dev_bypass' => true],
        );
    }
}
