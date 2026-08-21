<?php

/**
 * TimeClock Plus (TCP) On-Demand API.
 *
 * TCP is the source of truth for punches. Scheduling pulls work segments from
 * it; it never pushes schedule data back.
 *
 * The vendor documentation could not be read while writing this file. Every
 * value that is an inference rather than something we were told is tagged
 * GUESS. Treat a GUESS as a thing to confirm against a live response before
 * anyone relies on it in production.
 */
return [
    'base_uri' => env('TCP_BASE_URI', 'https://api.tcplusondemand.com/v1'),

    /**
     * 'oauth'  — exchange client credentials for a short-lived token.
     * 'static' — a long-lived token pasted into the env, no token call.
     */
    'auth_mode' => env('TCP_AUTH_MODE', 'oauth'),

    'oauth' => [
        // CONFIRMED by the workflow document: a different host from base_uri,
        // so this is an absolute URL and TokenProvider honours it as one.
        'token_path' => env('TCP_TOKEN_PATH', 'https://auth.api.tcplusondemand.com/oauth2/token'),
        'grant_type' => 'client_credentials',
        'client_id' => env('TCP_CLIENT_ID'),
        'client_secret' => env('TCP_CLIENT_SECRET'),

        // GUESS: no scope string is documented; empty means "send no scope".
        'scope' => env('TCP_SCOPE', ''),

        // GUESS: the token response carries an expires_in we have not seen, so
        // we refresh this many seconds early rather than trust it blindly.
        'refresh_skew_seconds' => (int) env('TCP_REFRESH_SKEW_SECONDS', 60),
    ],

    // Used only when auth_mode is 'static'.
    'static_token' => env('TCP_STATIC_TOKEN'),

    /**
     * How the token is presented on each request. Both modes use these.
     */
    'auth_header' => env('TCP_AUTH_HEADER', 'Authorization'),
    'auth_prefix' => env('TCP_AUTH_PREFIX', 'Bearer'),

    /**
     * A gateway API key, sent on every call ALONGSIDE the bearer token.
     *
     * These are two different things and both are required: the token says who
     * is calling, the key says which application is allowed through the gateway
     * at all. The token host (auth.api.tcplusondemand.com/oauth2/token) and the
     * shape of the client id are Cognito-behind-API-Gateway, where the key
     * conventionally rides as `x-api-key`.
     *
     * GUESS: the header NAME. If the gateway rejects the call with a 403 while
     * the token itself is fine, this is the line to change.
     *
     * Empty means omit it entirely — sending a blank key is a different request
     * from sending none, and gateways reject the blank one.
     */
    'api_key' => env('TCP_API_KEY'),

    'api_key_header' => env('TCP_API_KEY_HEADER', 'x-api-key'),

    /**
     * Some TCP tenants are addressed by a customer id alongside the token.
     * When TCP_CUSTOMER_ID is empty the header must be OMITTED ENTIRELY —
     * sending it blank is not the same as not sending it.
     */
    'customer_id' => env('TCP_CUSTOMER_ID'),

    // GUESS: the header name for the customer id is not documented.
    'customer_header' => env('TCP_CUSTOMER_HEADER', 'X-Customer-Id'),

    'pagination' => [
        // What we actually ask for. GUESS: 200 is a compromise between round
        // trips and response size; nothing says 200 is optimal.
        'page_size' => (int) env('TCP_PAGE_SIZE', 200),

        // The API's own behaviour: 50 when per_page is omitted, 1000 ceiling.
        'per_page_default' => 50,
        'per_page_max' => 1000,
    ],

    'retry' => [
        // GUESS: three attempts with escalating backoff. Chosen for a sync that
        // runs on a schedule and can afford to fail and be retried next run.
        'attempts' => (int) env('TCP_RETRY_ATTEMPTS', 3),
        'backoff_ms' => [500, 1000, 2000],
    ],

    // GUESS: request timeout in seconds. Punch pulls are small; if one takes
    // longer than this something is wrong upstream.
    'timeout' => (int) env('TCP_TIMEOUT', 30),

    /**
     * Maximum number of values we will put in a single "id in (...)" style
     * filter. Longer lists are chunked into several requests.
     */
    'filter_value_cap' => (int) env('TCP_FILTER_VALUE_CAP', 20),

    /**
     * KEEPING THE OPEN BOARD LIVE.
     *
     * There is no webhook and no subscription on this API — nothing in the
     * vendor's surface can tell us a punch happened. "As soon as it appears in
     * TCP" therefore has a floor, and the floor is how often we ask. These two
     * numbers are that floor, and they compose: a punch shows up on a board
     * within refresh_seconds + poll_seconds in the worst case.
     *
     * The work is driven by whoever is LOOKING at a board rather than by a
     * blanket sweep, which is what makes a short interval affordable: one open
     * week costs one request every refresh_seconds no matter how many managers
     * and tabs are watching it, because the refresh is behind a lock. Stores
     * nobody has open are covered by the estate-wide sweep in
     * routes/console.php, at a much longer cadence.
     */
    'live' => [
        // How stale the visible range may get before the next poll asks TCP
        // again. Also the lock's practical period: extra pollers inside the
        // window are answered from what the first one fetched.
        'refresh_seconds' => (int) env('TCP_LIVE_REFRESH_SECONDS', 20),

        // How often the browser asks us whether anything changed. Deliberately
        // shorter than refresh_seconds: the poll is a cheap indexed count
        // against our own database, and it is what makes a punch fetched by
        // somebody else's poll appear on this screen promptly.
        'poll_seconds' => (int) env('TCP_LIVE_POLL_SECONDS', 10),

        // A tab left open overnight must not keep a store's sync warm until
        // morning. After this long with no interaction the page stops polling
        // and says so, and any click or keypress starts it again.
        'idle_timeout_seconds' => (int) env('TCP_LIVE_IDLE_TIMEOUT_SECONDS', 1800),

        // How long a refresh may run before the next poller is allowed to try
        // again. Above the vendor's own timeout, so a slow call is waited out
        // rather than raced.
        'lock_seconds' => (int) env('TCP_LIVE_LOCK_SECONDS', 45),

        /*
         * The RENDER's threshold, deliberately far laxer than refresh_seconds.
         *
         * Rendering a board also reads the range, so that a grid's first paint
         * is never an empty lie — but every approve and correction redirects
         * back onto that render, and a person is sitting through the wait. On
         * the heartbeat's own interval a manager working down a week of
         * approvals would hit a vendor round trip every third click. Here they
         * hit one on arrival and none after, while the heartbeat keeps the grid
         * current in the background.
         *
         * A range with NO reading behind it is refreshed regardless of this.
         */
        'render_max_age_seconds' => (int) env('TCP_LIVE_RENDER_MAX_AGE_SECONDS', 300),
    ],
];
