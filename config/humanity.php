<?php

/**
 * Humanity (shiftplanning) API.
 *
 * Humanity is where published shifts land. It is a write target, not a source
 * of truth — the Humanity shift id we get back is stored on shifts, and the
 * entity mappings live in integration_identities. Neither is a projection, so
 * a stream replay must never be allowed to clear them.
 *
 * The vendor documentation could not be read while writing this file. Every
 * value that is an inference rather than something we were told is tagged
 * GUESS. Confirm a GUESS against a live response before relying on it.
 */
return [
    'base_uri' => env('HUMANITY_BASE_URI', 'https://www.humanity.com/api/v2'),

    /**
     * 'oauth'  — exchange username/password for a short-lived token.
     * 'static' — a long-lived token pasted into the env, no token call.
     *
     * The same two modes TCP has, and the same trade-off: static is the fast
     * way to get a real request working, but it opts OUT of the 401 refresh in
     * AbstractApiClient, which only fires for 'oauth'. An expired static token
     * therefore fails every call until somebody edits the env, where an oauth
     * one repairs itself once and carries on.
     */
    'auth_mode' => env('HUMANITY_AUTH_MODE', 'oauth'),

    // Used only when auth_mode is 'static'.
    'static_token' => env('HUMANITY_STATIC_TOKEN'),

    'oauth' => [
        'token_path' => env('HUMANITY_TOKEN_PATH', '/oauth2/token'),
        'grant_type' => 'password',
        'client_id' => env('HUMANITY_CLIENT_ID'),
        'client_secret' => env('HUMANITY_CLIENT_SECRET'),
        'username' => env('HUMANITY_USERNAME'),
        'password' => env('HUMANITY_PASSWORD'),

        // GUESS: the token response carries an expires_in we have not seen, so
        // we refresh this many seconds early rather than trust it blindly.
        'refresh_skew_seconds' => (int) env('HUMANITY_REFRESH_SKEW_SECONDS', 60),
    ],

    /**
     * How the access token rides along on each request.
     *
     * '_token' — appended as a query parameter, which is what Humanity's own
     *            examples do.
     * 'bearer' — sent as an Authorization header instead.
     */
    'auth_transport' => env('HUMANITY_AUTH_TRANSPORT', '_token'),

    // GUESS: the query parameter name is '_token' to match the transport name
    // above; nothing we can read confirms the spelling.
    'token_query_param' => env('HUMANITY_TOKEN_QUERY_PARAM', '_token'),

    /**
     * What "delete this shift" means when the shift belongs to a recurring
     * series.
     *
     * 'following' — this occurrence and the ones after it. The default,
     *               because it is the survivable mistake.
     * 'all'       — the ENTIRE series, including occurrences already in the
     *               past. This wipes history and cannot be undone from here,
     *               so a caller has to ask for it explicitly.
     */
    'delete_rule' => env('HUMANITY_DELETE_RULE', 'following'),

    /**
     * What "swap this shift" means.
     *
     * 'reassign' — move the shift to the new employee. One shift changes hands.
     * 'trade'    — the two employees exchange their shifts with each other.
     */
    'swap_strategy' => env('HUMANITY_SWAP_STRATEGY', 'reassign'),

    'retry' => [
        // GUESS: three attempts with escalating backoff, matching the TCP
        // client so operators only have one retry shape to reason about.
        'attempts' => (int) env('HUMANITY_RETRY_ATTEMPTS', 3),
        'backoff_ms' => [500, 1000, 2000],
    ],

    // GUESS: request timeout in seconds.
    'timeout' => (int) env('HUMANITY_TIMEOUT', 30),
];
