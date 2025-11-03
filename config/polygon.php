<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Polygon REST API Configuration
    |--------------------------------------------------------------------------
    |
    | Core Polygon.io credentials and endpoints for all upstream data requests.
    | These values are pulled from your .env file.
    |
    */

    'rest_api_key'       => env('POLYGON_API_KEY'),
    'rest_api_endpoint'  => env('POLYGON_API_ENDPOINT', 'https://api.polygon.io'),

    /*
    |--------------------------------------------------------------------------
    | Polygon Amazon S3 Flatfile Configuration
    |--------------------------------------------------------------------------
    |
    | Used for paid accounts that have access to bulk flatfile downloads.
    | These values specify the AWS S3 credentials and endpoints required
    | for historical data synchronization.
    |
    */

    's3_key'         => env('POLYGON_S3_KEY'),
    's3_secret'      => env('POLYGON_S3_SECRET'),
    's3_endpoint'    => env('POLYGON_S3_ENDPOINT', 'https://files.polygon.io'),
    's3_bucket'      => env('POLYGON_S3_BUCKET', 'flatfiles'),
    's3_region'      => env('POLYGON_S3_REGION', 'us-east-1'),
    's3_path_prefix' => env('POLYGON_S3_PATH_PREFIX', 'us_stocks_sip/'),

    /*
    |--------------------------------------------------------------------------
    | Default Price History Ingestion Parameters
    |--------------------------------------------------------------------------
    |
    | These values define the baseline date range and resolution used for
    | both initial ingestion and automated re-ingest operations triggered
    | by the integrity validation command.
    |
    | The integrity scan command (v2.6.1+) references these values to ensure
    | all historical price data stays consistent and aligned across both
    | ingestion and remediation workflows.
    |
    | Corresponding .env variables:
    |   POLYGON_PRICE_HISTORY_MIN_DATE=2020-01-01
    |   POLYGON_DEFAULT_TIMESPAN=day
    |   POLYGON_DEFAULT_MULTIPLIER=1
    |
    */

    // Minimum date boundary for ingestion (baseline dataset start)
    'price_history_min_date' => env('POLYGON_PRICE_HISTORY_MIN_DATE', '2020-01-01'),

    // Default time aggregation unit ("day", "minute", etc.)
    'default_timespan' => env('POLYGON_DEFAULT_TIMESPAN', 'day'),

    // Default multiplier (1 = 1/day, 5 = 5/day for custom aggregates)
    'default_multiplier' => env('POLYGON_DEFAULT_MULTIPLIER', 1),

    // Maximum allowed date (typically "today", but can be pinned for testing)
    'price_history_max_date' => env('POLYGON_PRICE_HISTORY_MAX_DATE', now()->toDateString()),

    /*
    |--------------------------------------------------------------------------
    | Request and Retry Settings
    |--------------------------------------------------------------------------
    |
    | Centralized rate-limit handling and retry tuning for ingestion and probe
    | services. These can later be referenced by PolygonProbe or batch jobs.
    |
    */

    'request_timeout' => env('POLYGON_REQUEST_TIMEOUT', 10), // seconds
    'max_retries'     => env('POLYGON_MAX_RETRIES', 3),
    'retry_backoff'   => env('POLYGON_RETRY_BACKOFF', 0.5), // exponential base delay (s)

];
