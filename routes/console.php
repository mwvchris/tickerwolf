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
    | 1️⃣ Intraday Prefetch to Redis (1-min, 15-min delayed)
    |--------------------------------------------------------------------------
    | Runs every minute during US market hours.
    */
    $schedule->command('polygon:intraday-prices:prefetch')
        ->timezone('America/New_York')
        ->weekdays()
        ->between('09:25', '20:05')
        ->everyMinute()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path(env(
            'LOG_POLYGON_INTRADAY_PRICES_CRON',
            'logs/polygon/cron/intraday_prices_cron.log'
        )));

    /*
    |--------------------------------------------------------------------------
    | 2️⃣ Polygon: Ticker Universe
    |--------------------------------------------------------------------------
    */
    $schedule->command('polygon:tickers:ingest --market=stocks')
        ->timezone('America/Los_Angeles')
        ->dailyAt('19:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/tickers_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 3️⃣ Ticker Slugs (SEO)
    |--------------------------------------------------------------------------
    */
    $schedule->command('tickers:generate-slugs')
        ->timezone('America/Los_Angeles')
        ->dailyAt('19:45')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/tickers_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 4️⃣ Polygon: Ticker Overviews
    |--------------------------------------------------------------------------
    */
    $schedule->command('polygon:ticker-overviews:ingest --batch=1000 --sleep=5')
        ->timezone('America/Los_Angeles')
        ->dailyAt('20:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/ticker_overviews_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 5️⃣ Polygon: Fundamentals (heavier job)
    |--------------------------------------------------------------------------
    */
    $schedule->command('polygon:fundamentals:ingest --timeframe=all --gte=2019-01-01 --batch=2000 --sleep=1')
        ->timezone('America/Los_Angeles')
        ->dailyAt('20:45')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/fundamentals_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 6️⃣ Polygon: Price History (smart missing-date ingest)
    |--------------------------------------------------------------------------
    */
    $schedule->command('polygon:ticker-price-histories:ingest --resolution=1d --sleep=1')
        ->timezone('America/Los_Angeles')
        ->dailyAt('21:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/ticker_price_histories_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 7️⃣ Indicators (SMA/EMA/RSI/etc)
    |--------------------------------------------------------------------------
    */
    $schedule->command('tickers:compute-indicators --from=2019-01-01 --to=2030-01-01 --batch=3000 --sleep=1 --include-inactive')
        ->timezone('America/Los_Angeles')
        ->dailyAt('22:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/indicators_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 8️⃣ Ticker Snapshots / Metrics
    |--------------------------------------------------------------------------
    */
    $schedule->command('tickers:build-snapshots --from=2019-01-01 --to=2030-01-01 --batch=500 --sleep=1 --include-inactive')
        ->timezone('America/Los_Angeles')
        ->dailyAt('23:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/snapshots_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 9️⃣ Polygon: News Ingestion
    |--------------------------------------------------------------------------
    */
    $schedule->command('polygon:ticker-news:ingest --batch=500 --sleep=1')
        ->timezone('America/Los_Angeles')
        ->dailyAt('23:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/news_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 🔟 Intraday DB Purge (rolling retention)
    |--------------------------------------------------------------------------
    */
    $schedule->command('tickers:purge-old-intraday --days=8')
        ->timezone('America/Los_Angeles')
        ->dailyAt('00:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/cron/intraday_purge_cron.log'));

    /*
    |--------------------------------------------------------------------------
    | 1️⃣1️⃣ Polygon: Batch Cleanup (existing)
    |--------------------------------------------------------------------------
    */
    $schedule->command('polygon:batches:cleanup --completed --days=30')
        ->timezone('America/Los_Angeles')
        ->dailyAt('23:59')
        ->onSuccess(fn () => Log::info('Polygon batch cleanup ran successfully.'))
        ->appendOutputTo(storage_path('logs/polygon/cron/batch_cleanup_cron.log'));
});