<?php

/**
 * Humanity (shiftplanning) API.
 *
 * Humanity is where published shifts land. It is a write target, not a source
 * of truth — the Humanity shift id we get back is stored on shifts, and the
 * entity mappings live in integration_identities. Neither is a projection, so
 * a stream replay must never be allowed to clear them.
 *
 * The vendor documentation could not be read while writing this file, so every
 * inference was tagged GUESS. Most have since been CONFIRMED against
 * platform.humanity.com's own reference; the few that remain are tagged GUESS
 * still and should be checked against a live response before being relied on.
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
        // CONFIRMED by the workflow document, and note BOTH surprises: it is a
        // different host from base_uri (no /api/v2), and it ends in .php.
        // Absolute, so TokenProvider uses it verbatim.
        'token_path' => env('HUMANITY_TOKEN_PATH', 'https://www.humanity.com/oauth2/token.php'),
        'grant_type' => 'password',
        'client_id' => env('HUMANITY_CLIENT_ID'),
        'client_secret' => env('HUMANITY_CLIENT_SECRET'),
        'username' => env('HUMANITY_USERNAME'),
        'password' => env('HUMANITY_PASSWORD'),

        /**
         * Listed among the token endpoint's parameters alongside the credentials.
         * Sent only when set, because whether the password grant actually
         * requires it is not stated — and a redirect_uri that does not match the
         * one registered on the app is itself a rejection.
         */
        'redirect_uri' => env('HUMANITY_REDIRECT_URI'),

        // CONFIRMED: the token response is
        // {access_token, expires_in, token_type, scope, refresh_token}, so
        // expires_in is real and this is how early we refresh against it.
        'refresh_skew_seconds' => (int) env('HUMANITY_REFRESH_SKEW_SECONDS', 60),
    ],

    /**
     * How the access token rides along on each request. CONFIRMED that both
     * work: "Access token must be passed with the access_token query parameter
     * or in Authorization header as Bearer token on every API request."
     *
     * '_token' — appended as a query parameter, which is what Humanity's own
     *            examples do. The name of the parameter is token_query_param
     *            below; this value only chooses query over header.
     * 'bearer' — sent as an Authorization header instead.
     */
    'auth_transport' => env('HUMANITY_AUTH_TRANSPORT', '_token'),

    /**
     * CONFIRMED as 'access_token'.
     *
     * This was a GUESS of '_token' — taken from the transport name above — and
     * it was wrong. Every request carried its token under a name Humanity does
     * not read, which presents as a 401 on a credential that is perfectly good.
     */
    'token_query_param' => env('HUMANITY_TOKEN_QUERY_PARAM', 'access_token'),

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

    /**
     * Whether a published shift names the store's Humanity location.
     *
     * OFF, and this is the one place where doing less is the correct answer. A
     * shift's `location` is documented as a "Remote Location id" — the override
     * for work happening somewhere other than where the schedule lives — and it
     * is a separate field from the schedule_location_id a shift comes back with.
     * In this account every schedule is already per store ("Crew Member -
     * 3795-25"), so naming the location again says nothing new, and saying it
     * through the REMOTE location field would mark the store's whole week as
     * worked somewhere else.
     *
     * Turn it on if a live shift lands at the wrong location; the mapping it
     * needs is already in integration_identities either way.
     */
    'send_shift_location' => (bool) env('HUMANITY_SEND_SHIFT_LOCATION', false),

    /**
     * Whether an unassigned shift is published as an OPEN shift.
     *
     * ON. CONFIRMED that `type` is "0 -> Standard, 1 -> Open". An open shift on
     * our board is one nobody is assigned to yet, which is the same fact, and
     * publishing it as Standard-with-nobody-on-it hides it from the employees
     * who could pick it up.
     */
    'publish_open_shifts_as_open' => (bool) env('HUMANITY_PUBLISH_OPEN_SHIFTS_AS_OPEN', true),

    'retry' => [
        // GUESS: three attempts with escalating backoff, matching the TCP
        // client so operators only have one retry shape to reason about.
        'attempts' => (int) env('HUMANITY_RETRY_ATTEMPTS', 3),
        'backoff_ms' => [500, 1000, 2000],
    ],

    // GUESS: request timeout in seconds.
    'timeout' => (int) env('HUMANITY_TIMEOUT', 30),
];
