<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Polygon REST API Configuration
    |--------------------------------------------------------------------------
    |
    | Core Polygon.io REST endpoints and credentials for all upstream data
    | requests. Values are sourced directly from your .env file.
    |
    */

    'rest_api_key'      => env('POLYGON_API_KEY'),
    'rest_api_endpoint' => env('POLYGON_API_ENDPOINT', 'https://api.polygon.io'),

    /*
    |--------------------------------------------------------------------------
    | Polygon Amazon S3 Flatfile Configuration
    |--------------------------------------------------------------------------
    |
    | These settings apply only to Polygon paid tiers that support bulk S3
    | flatfile downloads. If enabled, they allow accelerated ingestion of
    | historical OHLCV and reference datasets.
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
    | These values serve as defaults for daily OHLCV ingestion via the
    | polygon:ticker-price-histories:ingest command. They also support
    | remediation jobs such as integrity scans and backfills.
    |
    | Corresponding .env variables:
    |   POLYGON_PRICE_HISTORY_MIN_DATE=2020-01-01
    |   POLYGON_DEFAULT_TIMESPAN=day
    |   POLYGON_DEFAULT_MULTIPLIER=1
    |
    */

    'price_history_min_date' => env('POLYGON_PRICE_HISTORY_MIN_DATE', '2020-01-01'),

    // “day”, “minute”, “hour”
    'default_timespan' => env('POLYGON_DEFAULT_TIMESPAN', 'day'),

    // 1 = 1/day, 5 = 5/day, etc.
    'default_multiplier' => env('POLYGON_DEFAULT_MULTIPLIER', 1),

    // Useful for testing or pinning to a specific date
    'price_history_max_date' => env('POLYGON_PRICE_HISTORY_MAX_DATE', now()->toDateString()),

    /*
    |--------------------------------------------------------------------------
    | Intraday (1-Minute) Real-Time / Delayed Price Configuration
    |--------------------------------------------------------------------------
    |
    | These settings support the new intraday price subsystem built around:
    |   • PolygonRealtimePriceService
    |   • polygon:intraday-prices:prefetch scheduler
    |
    | The goal is to maintain a warm Redis cache of 1-minute price snapshots
    | with minimal API load and to serve fast, near-real-time prices across
    | the entire TickerWolf UI.
    |
    | Your current Polygon tier provides 15-minute delayed intraday data.
    | These configuration values abstract this away so the service layer can
    | handle it automatically.
    |
    | Corresponding .env overrides:
    |   POLYGON_INTRADAY_TIMESPAN=minute
    |   POLYGON_INTRADAY_MULTIPLIER=1
    |   POLYGON_INTRADAY_CACHE_TTL=900
    |   POLYGON_INTRADAY_REDIS_PREFIX=intraday
    |   POLYGON_INTRADAY_DELAY_MINUTES=15
    |   POLYGON_INTRADAY_MAX_AGE_MINUTES=1440
    |   POLYGON_MARKET_TIMEZONE=America/New_York
    |
    */

    // Polygon aggregate resolution for intraday
    'intraday_timespan'   => env('POLYGON_INTRADAY_TIMESPAN', 'minute'),

    // Almost always “1” for 1-minute bars
    'intraday_multiplier' => env('POLYGON_INTRADAY_MULTIPLIER', 1),

    // TTL for Redis-cached intraday snapshots (defaults to 15 min)
    'intraday_cache_ttl'  => env('POLYGON_INTRADAY_CACHE_TTL', 900),

    // Key namespace in Redis: intraday:{TICKER}:{DATE}
    'intraday_redis_prefix' => env('POLYGON_INTRADAY_REDIS_PREFIX', 'intraday'),

    // Market timezone (used for session classification)
    'market_timezone' => env('POLYGON_MARKET_TIMEZONE', 'America/New_York'),

    // Your plan includes 15-min delayed data — this handles offsets
    'intraday_delay_minutes' => env('POLYGON_INTRADAY_DELAY_MINUTES', 15),

    // How far back to request intraday data (in minutes)
    // Default: 1440 = 24 hours
    'intraday_max_age_minutes' => env('POLYGON_INTRADAY_MAX_AGE_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Request and Retry Settings
    |--------------------------------------------------------------------------
    |
    | Centralized connection + retry tuning for ingestion services.
    | These govern HTTP client behavior across:
    |   • PolygonPriceHistoryService
    |   • PolygonRealtimePriceService
    |   • Retry middleware
    |
    */

    'request_timeout' => env('POLYGON_REQUEST_TIMEOUT', 10),
    'max_retries'     => env('POLYGON_MAX_RETRIES', 3),
    'retry_backoff'   => env('POLYGON_RETRY_BACKOFF', 0.5),

];