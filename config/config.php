<?php

declare(strict_types=1);

use Modules\MES\Services\ErpStockMovementRecorder;

return [
    'name' => 'MES',

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    |
    | All MES module tables use this prefix to avoid collisions with ERP
    | or Core tables.
    |
    */
    // 'table_prefix' => 'mes_',

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

    'services' => [
        'stock_movement_recorder' => env('STOCK_MOVEMENT_RECORDER', ErpStockMovementRecorder::class)
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Maximum number of API requests per minute for MES endpoints.
    |
    */
    'rate_limit' => (int) env('MES_RATE_LIMIT', 60),

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
];
