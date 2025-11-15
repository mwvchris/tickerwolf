<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| Defines custom Artisan commands + the full application scheduler.
| Replaces the old app/Console/Kernel.php in Laravel 11+ / 12.
|
*/

// -------------------------------------------------------------------------
// Example Command
// -------------------------------------------------------------------------
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// -------------------------------------------------------------------------
// Scheduling Registration
// -------------------------------------------------------------------------
app()->afterResolving(Schedule::class, function (Schedule $schedule) {

    /*
    |--------------------------------------------------------------------------
    | 1️⃣ Intraday Prefetch to Redis (1-min, extended-hours aware)
    |--------------------------------------------------------------------------
    | Runs every minute on weekdays to warm Redis with 1-minute OHLCV snapshots
    | via PolygonRealtimePriceService. Combined with polygon.intraday_max_age_minutes,
    | this covers:
    |
    |   • Pre-market      (≈04:00–09:30 ET)
    |   • Regular session (09:30–16:00 ET)
    |   • After hours     (16:00–20:00 ET)
    |   • Overnight       (20:00–04:00 ET; for 24/5 trading symbols)
    |
    | Notes:
    |   • We no longer restrict by ->between('09:25','20:05'), so the prefetch
    |     runs 24h on weekdays. This guarantees that the rolling window of bars
    |     (configured by polygon.intraday_max_age_minutes) is continuously warm.
    */
    $schedule->command('polygon:intraday-prices:prefetch')
        ->timezone('America/New_York')
        ->weekdays()
        ->everyMinute()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path(env(
            'LOG_POLYGON_INTRADAY_PRICES_CRON',
            'logs/polygon/cron/intraday_prices_cron.log'
        )));

    /*
    |--------------------------------------------------------------------------
    | 2️⃣ Nightly Umbrella Refresh (tickers:refresh-all --daily)
    |--------------------------------------------------------------------------
    |
    | Runs the “daily” TickerWolf ingestion + analytics pipeline in a single,
    | ordered command. This maps directly to the --daily mode in the
    | TickersRefreshAll command and intentionally:
    |
    |   • Includes:
    |       1. polygon:tickers:ingest           (universe + new symbols)
    |       2. tickers:generate-slugs           (SEO slugs)
    |       3. polygon:ticker-overviews:ingest  (company metadata)
    |       4. polygon:ticker-price-histories:ingest (smart missing-date, windowed)
    |       5. tickers:compute-indicators       (technical indicators, 1d)
    |       6. tickers:build-snapshots          (feature snapshots / metrics)
    |       7. polygon:ticker-news:ingest       (news items, windowed / incremental)
    |       8. polygon:intraday-prices:prefetch (one-shot warm Redis snapshot)
    |
    |   • Skips:
    |       - polygon:fundamentals:ingest
    |
    | Fundamentals are handled separately in a weekly run (see schedule #3).
    |
    | Notes:
    |   • This runs in "safe" mode (no --fast flag) for nightly production-style
    |     refreshes. For manual local catch-up you can still run:
    |         php artisan tickers:refresh-all --dev
    |     which implies --fast and skips fundamentals/news.
    |   • Assumes the queue worker is running in the queue container.
    */
    $schedule->command('tickers:refresh-all --daily')
        ->timezone('America/Los_Angeles')
        ->dailyAt('20:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/tickers_refresh_all_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 3️⃣ Weekly Fundamentals-Only Umbrella (tickers:refresh-all --weekly)
    |--------------------------------------------------------------------------
    |
    | Runs the heavier fundamentals pipeline once per week. The --weekly mode
    | in TickersRefreshAll is defined to:
    |
    |   • Include:
    |       - polygon:fundamentals:ingest (2019+; all timeframes; windowed)
    |
    |   • Skip:
    |       - tickers / slugs / overviews
    |       - price histories
    |       - indicators
    |       - snapshots
    |       - news
    |       - intraday prefetch
    |
    | This keeps fundamentals fresh without hammering your box every night.
    */
    $schedule->command('tickers:refresh-all --weekly')
        ->timezone('America/Los_Angeles')
        ->weeklyOn(0, '22:30') // Sunday 22:30 PT
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/tickers_fundamentals_weekly_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 4️⃣ Intraday DB Purge (rolling retention)
    |--------------------------------------------------------------------------
    | Cleans up old intraday data (if/when you store it in DB) to keep tables
    | lean and reduce query overhead. Currently wired to:
    |
    |   tickers:purge-old-intraday --days=8
    |
    | meaning: keep ~8 days of intraday history.
    */
    $schedule->command('tickers:purge-old-intraday --days=8')
        ->timezone('America/Los_Angeles')
        ->dailyAt('00:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/intraday_purge_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 🔁 Hourly (1h) Price History Ingestion — Daily Post-Close
    |--------------------------------------------------------------------------
    | Fetches the latest 7-day rolling 1h bars with redundancy, and automatically
    | purges hourly bars older than 7 days.
    |
    | Runs after market close (NYSE: 4pm ET → 1pm PT).
    | Here we schedule for 4:30pm PT to allow Polygon finalization.
    */
    $schedule->command('polygon:ticker-price-histories:ingest --resolution=1h --batch=600 --sleep=0')
        ->timezone('America/Los_Angeles')
        ->dailyAt('16:30')   // 4:30 PM PT
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/hourly_price_history_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 5️⃣ Polygon: Batch Cleanup
    |--------------------------------------------------------------------------
    | Cleans up completed/failed job batches older than 30 days to keep the
    | job_batches table from growing unbounded.
    */
    $schedule->command('polygon:batches:cleanup --completed --days=30')
        ->timezone('America/Los_Angeles')
        ->dailyAt('23:59')
        ->onSuccess(fn () => Log::info('Polygon batch cleanup ran successfully.'))
        ->appendOutputTo(storage_path('logs/polygon/cron/batch_cleanup_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 6️⃣ Queue Supervisor
    |--------------------------------------------------------------------------
    |
    | Runs every 5 minutes to:
    |   • Log queue health and backlog
    |   • Trigger queue:restart when thresholds are exceeded
    |
    | Safe in both dev + prod. In dev, it simply keeps your workers sane
    | while you experiment with large ingest runs.
    */
    $schedule->command('queue:supervisor')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/queue/supervisor.log'));

    /*
    |--------------------------------------------------------------------------
    | 🔍 (Optional) Granular Nightly Steps [DISABLED]
    |--------------------------------------------------------------------------
    |
    | The following used to be scheduled individually:
    |
    |   polygon:tickers:ingest
    |   tickers:generate-slugs
    |   polygon:ticker-overviews:ingest
    |   polygon:fundamentals:ingest
    |   polygon:ticker-price-histories:ingest
    |   tickers:compute-indicators
    |   tickers:build-snapshots
    |   polygon:ticker-news:ingest
    |
    | They are now orchestrated via tickers:refresh-all instead.
    | If you ever want to go back to fully granular scheduling, you can
    | reintroduce those schedule definitions here.
    */
});