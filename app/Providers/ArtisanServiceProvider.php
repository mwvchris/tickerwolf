<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class ArtisanServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register all custom Artisan commands here.
        $this->commands([
            \App\Console\Commands\PolygonTickersIngest::class,
            \App\Console\Commands\TickersGenerateSlugs::class,
            \App\Console\Commands\PolygonTickerOverviewsIngest::class,
            \App\Console\Commands\PolygonTickerOverviewsRetry::class,
            \App\Console\Commands\PolygonTickerPriceHistoriesIngestLegacy::class,
            \App\Console\Commands\PolygonTickerPriceHistoryIngest::class,
            \App\Console\Commands\PolygonTickerPricesIngestIncremental::class,
            \App\Console\Commands\PolygonBatchStatus::class,
            \App\Console\Commands\PolygonBatchCleanup::class,
            \App\Console\Commands\PolygonDataReset::class,
            \App\Console\Commands\MonitorBatches::class,
            \App\Console\Commands\PruneJobBatches::class,
            \App\Console\Commands\PolygonFundamentalsIngest::class,
            \App\Console\Commands\PolygonIndicatorsIngest::class,
            \App\Console\Commands\PolygonTickerNewsIngest::class,
            \App\Console\Commands\TickersComputeIndicators::class,
            \App\Console\Commands\TickersBuildSnapshots::class,
            \App\Console\Commands\TickersValidateDataCommand::class,
            \App\Console\Commands\TickersBackfillIndicatorsCommand::class,
            \App\Console\Commands\TickersIntegrityScanCommand::class,
        ]);
    }
}