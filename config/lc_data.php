<?php

/**
 * LC_PIZZA_DATA — the estate's sales and operations warehouse.
 *
 * A READ SOURCE AND NOTHING ELSE. Scheduling asks it what a store took in each
 * hour; it never writes there, and nothing it returns is stored here beyond a
 * cache entry. That is deliberate: sales are LC_PIZZA_DATA's fact, and a copy
 * of somebody else's fact in this database is a copy that goes stale in a way
 * nobody notices until a manager staffs against last month's numbers.
 *
 * AUTH IS THE CALLER'S OWN TOKEN, forwarded. Both services trust the same auth
 * server, and LC_PIZZA_DATA's `auth.token.store` middleware verifies the token
 * AND asks whether that person may read that store. Forwarding therefore keeps
 * one answer to "who may see this store's sales" instead of two that can
 * disagree — and it means scheduling holds no credential that would let it read
 * every store's revenue on behalf of somebody who may only see one.
 *
 * The cost of that choice, stated plainly: there is no service token here, so
 * nothing can read sales outside a request that carries a person's token. A
 * queued job or a console command gets nothing. That is the right trade for a
 * feature that only ever renders on somebody's screen.
 */
return [
    /**
     * Where the warehouse's API lives, INCLUDING its /api prefix — its routes
     * are registered under api.php, so a base_uri without it 404s every call.
     */
    'base_uri' => env('LC_DATA_BASE_URI', 'http://localhost:8001/api'),

    /**
     * Off means the board renders exactly as it did before this feature: no
     * sales column, no failed request, no log line. The escape hatch for an
     * environment that has no warehouse to talk to — a developer laptop, a test
     * run — rather than something to leave off in production.
     */
    'enabled' => (bool) env('LC_DATA_ENABLED', true),

    /**
     * A token to use when the caller has none of their own.
     *
     * FOR THE DEV BYPASS, and nothing else. AUTH_SERVICE_DEV_BYPASS signs a
     * session in with a sentinel string rather than a real token, so there is
     * nothing to forward and the sales column can never render — which is a
     * silly way for a feature to be undevelopable on the setup this project
     * uses by default.
     *
     * HONOURED ONLY IN local AND testing. That check lives in LcDataClient, in
     * code, for the reason DevBypass gives about its own: this file's values
     * travel in a .env, and a .env is the thing people copy to a server by
     * accident.
     *
     * It must be a token LC_PIZZA_DATA's auth.token.store middleware will
     * accept, which means the auth service has to be reachable from THERE.
     * Empty means "no fallback" — the honest default.
     */
    'dev_token' => env('LC_DATA_DEV_TOKEN'),

    /**
     * Draw the column from a local generator instead of calling the warehouse.
     *
     * LOCAL AND TESTING ONLY, enforced in HourlySalesReader rather than here.
     *
     * WHY THIS EXISTS: seeing this feature work otherwise means standing up
     * MySQL, three warehouse databases, a set of partitioned migrations and an
     * auth service — a great deal of ceremony to look at fourteen numbers. The
     * generator reproduces HourlyStoreSalesSeeder's arithmetic exactly, so what
     * you see here is what you would see once that seeder has run against a
     * real warehouse.
     *
     * IT IS INVENTED DATA AND THE GRID SAYS SO. The column renders with a
     * SAMPLE badge whenever this is on. Anything else and a screenshot of
     * made-up revenue eventually turns up in a slide deck.
     */
    'stub' => (bool) env('LC_DATA_STUB', false),

    /**
     * The hours the sales column shows, store-local, inclusive of both ends.
     *
     * 10 → 23 is "10AM until midnight": fourteen buckets, the last of which is
     * the 23:00 hour. Midnight itself is the END of the window, not a bucket in
     * it — an order at 00:30 belongs to the following business date, and the
     * warehouse dates it that way.
     *
     * The window is presentation only. The endpoint returns every hour it has,
     * so widening this shows more without any change upstream.
     */
    'window' => [
        'from_hour' => (int) env('LC_DATA_WINDOW_FROM_HOUR', 10),
        'to_hour' => (int) env('LC_DATA_WINDOW_TO_HOUR', 23),
    ],

    /**
     * How long a day's hourly figures are trusted before asking again.
     *
     * TWO TTLS, because a finished day and a day in progress are different
     * kinds of fact. A business date that has already ended does not change
     * again except by a re-import, so it is cached for hours; today's numbers
     * are still being made and are cached for minutes.
     *
     * Zero on either disables caching for that class of day.
     */
    'cache' => [
        'closed_day_ttl' => (int) env('LC_DATA_CACHE_CLOSED_TTL', 21600),
        'open_day_ttl' => (int) env('LC_DATA_CACHE_OPEN_TTL', 300),
    ],

    // Matching the vendor clients so operators have one retry shape to reason
    // about. This sits on the board render path, so the budget is small: a
    // manager waiting on a grid would rather see a dash than a spinner.
    'retry' => [
        'attempts' => (int) env('LC_DATA_RETRY_ATTEMPTS', 2),
        'backoff_ms' => [200, 500],
    ],

    'timeout' => (int) env('LC_DATA_TIMEOUT', 5),
];
