<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use App\Schedules\PolygonTickerOverviewsSchedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file defines your application's custom Artisan commands and schedules.
| It replaces the old app/Console/Kernel.php from pre-Laravel 11 versions.
|
*/

// -------------------------------------------------------------------------
// Simple Example Command
// -------------------------------------------------------------------------
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// -------------------------------------------------------------------------
// Application Schedule Registration
// -------------------------------------------------------------------------
app()->afterResolving(Schedule::class, function (Schedule $schedule) {

    /*
    |--------------------------------------------------------------------------
    | Polygon: Ticker Overview Ingestion
    |--------------------------------------------------------------------------
    | Runs nightly at 8 PM Pacific to pull the latest ticker overview data.
    */
    $schedule->command('polygon:ticker-overviews:ingest')
        ->dailyAt('20:00')
        ->timezone('America/Los_Angeles')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/ticker_overviews_cron.log'));


    /*
    |--------------------------------------------------------------------------
    | Polygon: Ticker Price History Ingestion
    |--------------------------------------------------------------------------
    | Runs nightly at 9 PM Pacific to update price history data for all tickers.
    */
    $schedule->command('polygon:ticker-price-histories:ingest --limit=1000')
        ->dailyAt('21:00')
        ->timezone('America/Los_Angeles')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/polygon/ticker_price_histories_cron.log'));


    /*
    |--------------------------------------------------------------------------
    | Polygon: Batch Cleanup
    |--------------------------------------------------------------------------
    | Cleans up completed or failed job batches older than 30 days.
    | Keeps the job_batches table lean and prevents log bloat.
    */
    $schedule->command('polygon:batches:cleanup --completed --days=30')
        ->dailyAt('23:59')
        ->timezone('America/Los_Angeles')
        ->onSuccess(fn () => Log::info('Polygon batch cleanup ran successfully.'))
        ->appendOutputTo(storage_path('logs/polygon/batch_cleanup_cron.log'));


    /*
    |--------------------------------------------------------------------------
    | Optional: Additional Polygon Schedules
    |--------------------------------------------------------------------------
    | Load modular schedules (if you have additional scheduling logic in classes)
    | such as App\Schedules\PolygonTickerOverviewsSchedule.
    */
    // (new PolygonTickerOverviewsSchedule())($schedule);
});
