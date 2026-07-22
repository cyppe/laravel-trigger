<?php

declare(strict_types=1);
/**
 * This file is part of huangdijia/laravel-trigger.
 *
 * @link     https://github.com/huangdijia/laravel-trigger
 * @document https://github.com/huangdijia/laravel-trigger/blob/4.x/README.md
 * @contact  huangdijia@gmail.com
 */
return [
    'default' => 'default',

    'replications' => [
        'default' => [
            'host' => env('TRIGGER_HOST', ''),
            'port' => env('TRIGGER_PORT', 3306),
            'user' => env('TRIGGER_USER', ''),
            'password' => env('TRIGGER_PASSWORD', ''),

            // detect from trigger routers
            'detect' => (bool) env('TRIGGER_DETECT', false),
            // or set database and tables
            'databases' => env('TRIGGER_DATABASES', '') ? explode(',', env('TRIGGER_DATABASES')) : [],
            'tables' => env('TRIGGER_TABLES', '') ? explode(',', env('TRIGGER_TABLES')) : [],

            // Optional low-level replication filters. Keep empty to preserve the
            // package's existing behavior.
            'events_only' => env('TRIGGER_EVENTS_ONLY', '') ? explode(',', env('TRIGGER_EVENTS_ONLY')) : [],
            'events_ignore' => env('TRIGGER_EVENTS_IGNORE', '') ? explode(',', env('TRIGGER_EVENTS_IGNORE')) : [],
            'databases_ignore' => env('TRIGGER_DATABASES_IGNORE', '') ? explode(',', env('TRIGGER_DATABASES_IGNORE')) : [],
            'tables_ignore' => env('TRIGGER_TABLES_IGNORE', '') ? explode(',', env('TRIGGER_TABLES_IGNORE')) : [],
            'databases_regex' => env('TRIGGER_DATABASES_REGEX', '') ? explode(',', env('TRIGGER_DATABASES_REGEX')) : [],
            'tables_regex' => env('TRIGGER_TABLES_REGEX', '') ? explode(',', env('TRIGGER_TABLES_REGEX')) : [],

            // v11 can resolve names from FULL TABLE_MAP metadata and otherwise
            // falls back to information_schema. Default off for a safe rollout.
            'use_table_map_metadata' => (bool) env('TRIGGER_USE_TABLE_MAP_METADATA', false),

            // Optional fail-closed source safety gates.
            'require_full_row_image' => (bool) env('TRIGGER_REQUIRE_FULL_ROW_IMAGE', false),
            'reject_transaction_compression' => (bool) env('TRIGGER_REJECT_TRANSACTION_COMPRESSION', false),
            'reject_partial_json_updates' => (bool) env('TRIGGER_REJECT_PARTIAL_JSON_UPDATES', false),
            // Stop on v11 event types whose mutation payload is not decoded.
            'reject_unsupported_mutation_events' => (bool) env('TRIGGER_REJECT_UNSUPPORTED_MUTATION_EVENTS', false),

            'heartbeat' => (int) env('TRIGGER_HEARTBEAT', 3),

            // Periodically ping the MySQL metadata connection to avoid server-side idle disconnects.
            // Set to 0 to disable.
            'keepalive' => (int) env('TRIGGER_KEEPALIVE', 0),

            // Combine reset/restart checks into one cache read and poll at most
            // once per interval. Zero preserves the legacy per-delivered-event poll.
            'control_poll_interval' => (int) env('TRIGGER_CONTROL_POLL_INTERVAL', 0),

            // Persist the binlog resume position from the busy dispatch path at most
            // once per this many seconds (heartbeats only fire on an idle stream).
            // Set to 0 to only checkpoint on heartbeats (previous behavior).
            'checkpoint_interval' => (int) env('TRIGGER_CHECKPOINT_INTERVAL', 5),

            // legacy: resume from the existing per-event checkpoint.
            // shadow: keep legacy resume behavior and populate a separate checkpoint
            // only at confirmed transaction boundaries.
            // safe: resume only from the transaction-boundary checkpoint and fail
            // closed when it is missing. Roll out shadow before safe.
            'checkpoint_mode' => env('TRIGGER_CHECKPOINT_MODE', 'legacy'),

            // How long a legacy resume position stays valid. Transaction-safe
            // cursors never expire because stale is safer than absent.
            'checkpoint_ttl' => (int) env('TRIGGER_CHECKPOINT_TTL', 86400),

            // MySQL session variables to apply on connect (for the metadata connection).
            // Example:
            // - wait_timeout=7200,interactive_timeout=7200
            'session_variables' => env('TRIGGER_SESSION_VARIABLES', '')
                ? array_filter(array_map('trim', explode(',', (string) env('TRIGGER_SESSION_VARIABLES'))))
                : [],
            'subscribers' => [
                // Huangdijia\Trigger\Subscribers\Heartbeat::class,
            ],
            'route' => app()->basePath('routes/trigger.php'),
        ],
    ],
];
