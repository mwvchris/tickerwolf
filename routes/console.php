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
    | 1️⃣ Intraday Prefetch → Redis (1-min, fully extended-hours aware)
    |--------------------------------------------------------------------------
    |
    | Runs EVERY MINUTE, EVERY DAY — including SUNDAY evening.
    |
    | This is required because Polygon restarts extended-hours data on:
    |       • Sunday @ ~18:00 ET
    |
    | Polygon trading coverage (extended + regular):
    |       • Sunday 18:00 ET  → Friday 20:00 ET (24/5)
    |       • Friday 20:00 ET → Sunday 18:00 ET (no intraday bars)
    |
    | Why daily instead of weekdays?
    | ---------------------------------
    |   Because Sunday 18:00–20:00 ET is *active* after-hours trading,
    |   and we want intraday snapshots to be reflected immediately when
    |   visiting a ticker page during that time.
    |
    | polygon:intraday-prices:prefetch warms Redis according to:
    |   config('polygon.intraday_max_age_minutes')  // usually 1440 (24h)
    |
    | The job automatically handles the "no bars returned" gap between
    | Fri 20:00 ET and Sun 18:00 ET.
    */
    $schedule->command('polygon:intraday-prices:prefetch')
        ->timezone('America/New_York')
        ->everyMinute()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path(env(
            'LOG_POLYGON_INTRADAY_PRICES_CRON',
            'logs/polygon/cron/intraday_prices_cron.log'
        )));

    /*
    |--------------------------------------------------------------------------
    | 🔁 Sunday Re-Activation Safety Trigger
    |--------------------------------------------------------------------------
    |
    | This ensures that as soon as Polygon resumes extended-hours feeds on
    | Sunday evening (≈18:00 ET), the prefetcher is guaranteed to run
    | without waiting for the next full-day cron window.
    |
    | The job already runs every minute, but this provides a clean, explicit
    | “session restart heartbeat.”
    */
    $schedule->command('polygon:intraday-prices:prefetch')
        ->timezone('America/New_York')
        ->sundays()
        ->at('18:00')
        ->appendOutputTo(storage_path(env(
            'LOG_POLYGON_INTRADAY_PRICES_CRON',
            'logs/polygon/cron/intraday_prices_reactivation.log'
        )));

    /*
    |--------------------------------------------------------------------------
    | 2️⃣ Nightly Umbrella Refresh (tickers:refresh-all --daily)
    |--------------------------------------------------------------------------
    |
    | The main nightly ingest + analytics pipeline.
    | Runs “safe” mode (no --fast) and expects queue worker to be active.
    */
    $schedule->command('tickers:refresh-all --daily')
        ->timezone('America/Los_Angeles')
        ->dailyAt('20:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/tickers_refresh_all_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 3️⃣ Weekly Fundamentals Pipeline (tickers:refresh-all --weekly)
    |--------------------------------------------------------------------------
    |
    | Runs heavier fundamentals ingestion once per week.
    |
    | Sunday 22:30 PT (Monday early AM ET)
    */
    $schedule->command('tickers:refresh-all --weekly')
        ->timezone('America/Los_Angeles')
        ->weeklyOn(0, '22:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/tickers_fundamentals_weekly_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 4️⃣ Intraday DB Purge (rolling 8-day retention)
    |--------------------------------------------------------------------------
    */
    $schedule->command('tickers:purge-old-intraday --days=8')
        ->timezone('America/Los_Angeles')
        ->dailyAt('00:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/intraday_purge_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 5️⃣ 1-Hour Price History (post-close)
    |--------------------------------------------------------------------------
    |
    | Fetches rolling 1-hour bars nightly.
    */
    $schedule->command('polygon:ticker-price-histories:ingest --resolution=1h --batch=600 --sleep=0')
        ->timezone('America/Los_Angeles')
        ->dailyAt('16:30') // 4:30 PM PT
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/hourly_price_history_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 6️⃣ Polygon: Batch Cleanup (30-day retention)
    |--------------------------------------------------------------------------
    */
    $schedule->command('polygon:batches:cleanup --completed --days=30')
        ->timezone('America/Los_Angeles')
        ->dailyAt('23:59')
        ->onSuccess(fn () => Log::info('Polygon batch cleanup ran successfully.'))
        ->appendOutputTo(storage_path('logs/polygon/cron/batch_cleanup_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 7️⃣ Queue Supervisor (health monitoring)
    |--------------------------------------------------------------------------
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