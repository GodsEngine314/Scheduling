<?php

/**
 * The auth service (pizzasys) — the identity authority for the whole estate.
 *
 * Scheduling does NOT authenticate anyone itself. It holds no passwords, issues
 * no tokens and stores no credentials: `users` here is a projection of
 * auth.v1.user.*, and its own migration says "any write here is overwritten by
 * the next event". A password column on a projection is erased by the next
 * replay, silently, and only discovered when somebody cannot sign in.
 *
 * So a request arrives carrying a Sanctum token the auth service issued, and
 * scheduling asks the auth service what that token means:
 *
 *   POST {base_uri}/auth/token-verify
 *   Authorization: Bearer {service_token}      <- proves WE are scheduling
 *   { service, token, method, path, route_name }
 *
 * It answers OAuth2-introspection style — active, sub, roles, permissions — and
 * it also answers the AUTHORIZATION question in ext.authorized, evaluated
 * against rules held upstream. That is deliberate: one place decides who may do
 * what, rather than every service keeping its own copy of a permission map and
 * drifting.
 */
return [
    'base_uri' => env('AUTH_SERVICE_BASE_URI', 'http://localhost:8000/api/v1'),

    /**
     * How we identify ourselves to the auth service. This is the value of
     * service_clients.name upstream, and it is ALSO what scheduling's
     * authorization rules are filed under there — change it and every rule
     * stops matching.
     */
    'service_name' => env('AUTH_SERVICE_NAME', 'scheduling'),

    /**
     * The service client token, created upstream with `service-client:create`.
     * Stored there as a sha256 hash, so this plaintext exists in exactly one
     * place: this env. There is no oauth dance — it is a long-lived bearer.
     */
    'service_token' => env('AUTH_SERVICE_TOKEN'),

    /**
     * Seconds an introspection result is trusted before asking again.
     *
     * The trade this buys, stated plainly: a token revoked upstream keeps
     * working here for up to this long, and so does a permission just taken
     * away. Zero disables caching and puts a round trip in front of every
     * request, including every board render.
     */
    'cache_ttl' => (int) env('AUTH_SERVICE_CACHE_TTL', 30),

    /**
     * Whether to enforce ext.authorized, the upstream rule decision.
     *
     * Upstream defaults allow_if_no_rule = true, so with no scheduling rules
     * written yet this permits everything and authentication alone is the gate.
     * Turning it off is an escape hatch for the case where a bad rule locks the
     * console out — it is NOT a way to run without authorization, because a
     * signed-in user is still required either way.
     */
    'enforce_authorization' => (bool) env('AUTH_SERVICE_ENFORCE_AUTHORIZATION', true),

    /**
     * A local sign-in that does not need the auth service running.
     *
     * OFF BY DEFAULT, AND IT CANNOT BE TURNED ON OUTSIDE local/testing. See
     * App\Services\Auth\DevBypass: the environment check lives in code rather
     * than here, because this file's values travel in a .env and a .env is the
     * thing people copy to a server by accident.
     *
     * What it grants is super-admin. Treat the credential below as public — it
     * is in the repository — and never enable this anywhere that holds real
     * schedules or real pay rates.
     */
    'dev_bypass' => [
        'enabled' => (bool) env('AUTH_SERVICE_DEV_BYPASS', false),
        'username' => env('AUTH_SERVICE_DEV_BYPASS_USER', 'admin@admin.com'),
        'password' => env('AUTH_SERVICE_DEV_BYPASS_PASSWORD', 'admin'),

        /**
         * Which projected user a bypassed session is attributed to.
         *
         * created_by_user_id and friends are foreign keys into `users`, so this
         * has to be a row that exists or attribution is null. Defaults to the
         * seeded test account; null is a perfectly honest answer.
         */
        'user_id' => env('AUTH_SERVICE_DEV_BYPASS_USER_ID', 9001),
    ],

    // Matching the vendor clients so operators have one retry shape to reason
    // about. Introspection is on the request path, so the budget is small.
    'retry' => [
        'attempts' => (int) env('AUTH_SERVICE_RETRY_ATTEMPTS', 2),
        'backoff_ms' => [200, 500],
    ],

    'timeout' => (int) env('AUTH_SERVICE_TIMEOUT', 5),
];
