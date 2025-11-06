<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Correlation Engine Defaults
    |--------------------------------------------------------------------------
    |
    | These define the default parameters for the correlation matrix computation
    | across tickers. Command-line options in `compute:correlation-matrix`
    | will override these values when provided.
    |
    */

    'defaults' => [

        // Number of trading days of data to pull from ticker_price_histories
        'lookback_days' => 120,

        // Rolling window length (in returns) used to compute correlation/beta
        'window' => 20,

        // Ticker block size (chunk × chunk) to control memory footprint
        'chunk_size' => 200,

        // Minimum overlapping observations required between tickers
        'min_overlap' => 20,

        // Default ticker limit for safety (0 = all active tickers)
        'limit' => 0,

        // Number of pairs per bulk upsert operation
        'flush_every' => 5000,
    ],

];