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
];
