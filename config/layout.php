<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Layout & UI Presentation Settings
    |--------------------------------------------------------------------------
    |
    | These configuration values centralize all display-oriented parameters
    | for TickerWolf’s user interface. They serve as a single source of truth
    | for Blade templates, view models, and presentation helpers across the app.
    |
    | Values here are intentionally designed to be safe defaults while also
    | being fully overridable via environment variables.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Company Description Truncation
    |--------------------------------------------------------------------------
    |
    | Maximum character length for the “short” company description displayed
    | in the Ticker Profile right-hand sidebar. Longer descriptions are trimmed
    | gracefully at the nearest sentence boundary and a "Read More" modal is
    | offered to reveal the full text.
    |
    | Corresponding .env variable:
    |   LAYOUT_SHORT_DESCRIPTION_MAX=400
    |
    */

    'short_description_max' => env('LAYOUT_SHORT_DESCRIPTION_MAX', 400),

    /*
    |--------------------------------------------------------------------------
    | Chart Density & Data Presentation
    |--------------------------------------------------------------------------
    |
    | These settings control how price series are rendered across 1D–5Y
    | intervals. They are intentionally decoupled from ingestion parameters
    | so front-end visualization can evolve independently.
    |
    | Corresponding .env variables (optional):
    |   LAYOUT_CHART_MAX_POINTS_1D=500
    |   LAYOUT_CHART_MAX_POINTS_DEFAULT=300
    |
    */

    // Maximum number of intraday points for 1D before down-sampling
    'chart_max_points_1d' => env('LAYOUT_CHART_MAX_POINTS_1D', 500),

    // Default max points for 1W, 1M, 6M, 1Y, 5Y ranges
    'chart_max_points_default' => env('LAYOUT_CHART_MAX_POINTS_DEFAULT', 300),

    /*
    |--------------------------------------------------------------------------
    | Header / Metadata Display Settings
    |--------------------------------------------------------------------------
    |
    | Constants governing how header blocks, badges, and numeric summaries
    | appear across the Ticker profile page. These enable consistent formatting
    | from a central configuration location.
    |
    | Corresponding .env (optional):
    |   LAYOUT_HEADER_PRICE_DECIMALS=2
    |   LAYOUT_HEADER_PERCENT_DECIMALS=2
    |
    */

    'header_price_decimals'  => env('LAYOUT_HEADER_PRICE_DECIMALS', 2),
    'header_percent_decimals' => env('LAYOUT_HEADER_PERCENT_DECIMALS', 2),

    /*
    |--------------------------------------------------------------------------
    | Fallback & Safety Display Controls
    |--------------------------------------------------------------------------
    |
    | Controls for how missing data is presented (placeholders, dashes, etc.).
    | These allow you to adjust display behavior without modifying view logic.
    |
    | Corresponding .env (optional):
    |   LAYOUT_PLACEHOLDER_SYMBOL=—
    |
    */

    'placeholder_symbol' => env('LAYOUT_PLACEHOLDER_SYMBOL', '—'),

];