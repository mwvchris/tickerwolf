<?php

namespace App\Schedules;

use Illuminate\Support\Facades\Schedule;

/**
 * Schedule: PolygonIntradayPricesSchedule
 * -----------------------------------------------------------------------------
 * Keeps Redis warm by prefetching intraday (1-minute) aggregate data once
 * per minute. This ensures ultra-fast ticker profile page loads and significantly
 * reduces Polygon API requests on demand.
 *
 * Uses Redis sliding TTL caching via PolygonRealtimePriceService.
 *
 * Runs every minute in the America/New_York (market time) timezone.
 * -----------------------------------------------------------------------------
 */
class PolygonIntradayPricesSchedule
{
    /**
     * Define the application's command schedule.
     */
    public function __invoke(Schedule $schedule): void
    {
        /*
        |--------------------------------------------------------------------------
        | Prefetch intraday 1-minute snapshots
        |--------------------------------------------------------------------------
        |
        | Runs continuously throughout market hours (and after-hours).
        | 
        | We target 500 tickers per minute as a safe default.
        | Increase to 1000 once in production if needed.
        |
        */
        $schedule->command('polygon:intraday-prices:prefetch --limit=500')
            ->timezone('America/New_York')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(
                storage_path(env(
                    'LOG_POLYGON_INTRADAY_PRICES_CRON',
                    'logs/polygon/cron/intraday_prices_cron.log'
                ))
            );
    }
}