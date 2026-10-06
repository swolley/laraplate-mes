<?php

declare(strict_types=1);

return [
    'name' => 'MES',

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The queue connection and queue name used by MES jobs (backflush,
    | production order creation from sales orders, etc.).
    |
    */
    'queue' => [
        'connection' => env('MES_QUEUE_CONNECTION', 'database'),
        'name' => env('MES_QUEUE_NAME', 'mes'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Order Auto-Creation
    |--------------------------------------------------------------------------
    |
    | Settings for the pipeline that creates production orders from confirmed
    | sales orders. Only lines whose item has an active BOM are turned into a
    | production order.
    |
    | - default_warehouse: per-company map [company_id => warehouse_id] used as
    |   the receiving warehouse. When a company is absent from the map the
    |   resolver falls back to the company's sole warehouse, and skips the line
    |   when the target stays ambiguous.
    | - daily_minutes: working minutes per day used to turn the routing's
    |   standard minutes into a planned lead time.
    | - default_lead_time_days: planned lead time (working days) applied when the
    |   item has no routing to estimate from.
    |
    */
    'production' => [
        'default_warehouse' => [],
        'daily_minutes' => (float) env('MES_PRODUCTION_DAILY_MINUTES', 480),
        'default_lead_time_days' => (int) env('MES_PRODUCTION_DEFAULT_LEAD_TIME_DAYS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Recipients and channels for MES operational notifications.
    |
    | - stock_shortage: sent when a consumption cannot be fully covered by
    |   available stock. Recipients are resolved by role; channels follow the
    |   Laravel notification channel names (database, mail, ...).
    | - capacity_overload: sent when an operation starts on a work center whose
    |   materialised load for the day exceeds its available minutes. Same shape.
    | - machine_incident: sent when a machine incident is recorded (sequence gap,
    |   clock skew, failed message, refused authentication, silent device). Same shape.
    |
    */
    'notifications' => [
        'stock_shortage' => [
            'channels' => ['database'],
            'recipients' => [
                'roles' => ['admin', 'superadmin'],
            ],
        ],
        'capacity_overload' => [
            'channels' => ['database'],
            'recipients' => [
                'roles' => ['admin', 'superadmin'],
            ],
        ],
        'machine_incident' => [
            'channels' => ['database'],
            'recipients' => [
                'roles' => ['admin', 'superadmin'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Machine connectivity
    |--------------------------------------------------------------------------
    |
    | Machines and probes push `laraplate-machine/1` messages into the MES.
    |
    | - queue: queue that processes machine messages, so machine traffic never
    |   delays other MES work (connection: `mes.queue.connection`).
    | - max_samples / max_body_kb: limits of one HTTP message (413 above them).
    | - clock_skew_seconds: tolerated difference between the sender clock and
    |   the server before a `clock_skew` incident is recorded.
    | - inbox_retention_days: processed raw messages older than this are pruned;
    |   failed ones are kept.
    | - rate_limit_per_minute: HTTP requests accepted per source and minute.
    |
    */
    'machine' => [
        'queue' => env('MES_MACHINE_QUEUE', 'mes-machine'),
        'max_samples' => (int) env('MES_MACHINE_MAX_SAMPLES', 5000),
        'max_body_kb' => (int) env('MES_MACHINE_MAX_BODY_KB', 1024),
        'clock_skew_seconds' => (int) env('MES_MACHINE_CLOCK_SKEW_SECONDS', 30),
        'inbox_retention_days' => (int) env('MES_MACHINE_INBOX_RETENTION_DAYS', 7),
        'rate_limit_per_minute' => (int) env('MES_MACHINE_RATE_LIMIT_PER_MINUTE', 600),

        /*
        | The MQTT broker the bridge (`mes:machine-bridge`) connects to: one per installation, provided
        | by the customer. The session is persistent (QoS 1), so messages published while the bridge is
        | down wait on the broker. `topic_prefix` starts the topic of canonical sources.
        */
        'mqtt' => [
            'host' => env('MES_MACHINE_MQTT_HOST', '127.0.0.1'),
            'port' => (int) env('MES_MACHINE_MQTT_PORT', 1883),
            'username' => env('MES_MACHINE_MQTT_USERNAME'),
            'password' => env('MES_MACHINE_MQTT_PASSWORD'),
            'tls' => (bool) env('MES_MACHINE_MQTT_TLS', false),
            'client_id' => env('MES_MACHINE_MQTT_CLIENT_ID', 'laraplate-mes-bridge'),
            'topic_prefix' => env('MES_MACHINE_MQTT_TOPIC_PREFIX', 'laraplate'),
        ],
    ],
];
