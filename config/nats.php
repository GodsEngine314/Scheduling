<?php

$devMode = (int) env('DEV_MODE', 0) === 1;

$authSubject = $devMode
    ? 'auth.testing.v1.>'
    : 'auth.v1.>';

$hiringSubject = $devMode
    ? 'hiring.testing.v1.>'
    : 'hiring.v1.>';

$schedulingSubject = $devMode
    ? 'scheduling.testing.v1.>'
    : 'scheduling.v1.>';

return [
    'dev_mode' => $devMode,
    'host' => env('NATS_HOST', '127.0.0.1'),
    'port' => (int) env('NATS_PORT', 4222),

    'user' => env('NATS_USER'),
    'pass' => env('NATS_PASS'),
    'token' => env('NATS_TOKEN'),

    /**
     * Scheduling publishes its own facts and nothing else. Anything this
     * service learns from hiring or auth is re-published under hiring's or
     * auth's subject by hiring or auth — never re-emitted from here.
     */
    'publishers' => [
        [
            'name' => $devMode
                ? env('NATS_SCHEDULING_STREAM', 'SCHEDULING_TESTING_EVENTS')
                : env('NATS_SCHEDULING_STREAM', 'SCHEDULING_EVENTS'),
            'subjects' => [$schedulingSubject],
        ],
    ],

    /**
     * Add streams here as new projects appear.
     * Each stream gets its own durable pull consumer.
     *
     * The durable names are owned by THIS service (SCHEDULING_*) even though
     * the streams belong to hiring and auth — two consumers on one stream must
     * not share a durable or they steal each other's messages.
     */
    'streams' => [
        [
            'name' => $devMode ? env('NATS_HIRING_STREAM', 'HIRING_TESTING_EVENTS') : env('NATS_HIRING_STREAM', 'HIRING_EVENTS'),
            'durable' => $devMode ? env('NATS_HIRING_DURABLE', 'SCHEDULING_HIRING_TESTING_CONSUMER') : env('NATS_HIRING_DURABLE', 'SCHEDULING_HIRING_CONSUMER'),
            'filter_subject' => $hiringSubject, // match your stream subjects
        ],
        [
            'name' => $devMode ? env('NATS_AUTH_STREAM', 'AUTH_TESTING_EVENTS') : env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),
            'durable' => $devMode ? env('NATS_AUTH_DURABLE', 'SCHEDULING_AUTH_TESTING_CONSUMER') : env('NATS_AUTH_DURABLE', 'SCHEDULING_AUTH_CONSUMER'),
            'filter_subject' => $authSubject, // match your stream subjects
        ],
    ],

    'pull' => [
        'batch' => (int) env('NATS_PULL_BATCH', 25),
        'timeout_ms' => (int) env('NATS_PULL_TIMEOUT_MS', 2000),
        'sleep_ms' => (int) env('NATS_PULL_SLEEP_MS', 250),
    ],
];
