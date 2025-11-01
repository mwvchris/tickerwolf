<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Polygon REST API Config
    |--------------------------------------------------------------------------
    |
    | Here you may specify your Polygon REST API key and endpoint URL for accessing 
    | financial data for Polygon.io.
    |
    */

    'rest_api_key' => env('POLYGON_API_KEY'),
    'rest_apiendpoint' => "https://api.polygon.io/v3/reference/dividends?apiKey=".env('POLYGON_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Polygon Amazon S3 Flatfile Download Config (For paid accounts only)
    |--------------------------------------------------------------------------
    |
    | Amazon S3 credentials and endpoint for downloading Polygon flat files.
    |
    */

    's3_key' => env('POLYGON_S3_KEY'),
    's3_secret' => env('POLYGON_S3_SECRET'),
    's3_endpoint' => env('POLYGON_S3_ENDPOINT', 'https://files.polygon.io'),
    's3_bucket' => env('POLYGON_S3_BUCKET', 'flatfiles'),
    's3_region' => env('POLYGON_S3_REGION', 'us-east-1'),
    's3_path_prefix' => env('POLYGON_S3_PATH_PREFIX', 'us_stocks_sip/'),

];
