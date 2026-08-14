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
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Maximum number of API requests per minute for MES endpoints.
    |
    */
    // 'rate_limit' => (int) env('MES_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Lot Number Format
    |--------------------------------------------------------------------------
    |
    | Format used to auto-generate lot number codes.
    | Supported tokens: {YEAR}, {MONTH}, {DAY}, {SEQ}
    |
    */
    'lot_number_format' => env('MES_LOT_NUMBER_FORMAT', '{YEAR}{MONTH}{DAY}-{SEQ}'),

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
];
