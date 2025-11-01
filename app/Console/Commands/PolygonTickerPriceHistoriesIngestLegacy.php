<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Services\PolygonTickerPriceHistoryService;
use Illuminate\Support\Carbon;

class PolygonTickerPriceHistoriesIngestLegacy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example:
     * php artisan polygon:ticker-price-histories:ingest-legacy --resolution=1d --from=2019-01-01 --limit=0 --batch=1000 --sleep=15
     */
    protected $signature = 'polygon:ticker-price-histories:ingest-legacy
                            {--resolution=1d : Resolution (1d, 1m, 5m, etc.)}
                            {--from=2019-01-01 : Start date (YYYY-MM-DD)}
                            {--to=null : End date (YYYY-MM-DD) or null for today}
                            {--multiplier=1 : Multiplier for Polygon aggregates endpoint}
                            {--limit=0 : Limit total tickers processed (0 = all)}
                            {--batch=1000 : Tickers per chunk}
                            {--sleep=15 : Seconds to sleep between batches}';

    protected $description = 'LEGACY — Direct (non-queued) ingestion of Polygon ticker price histories.';

    public function handle(): int
    {
        $resolution = $this->option('resolution') ?? '1d';
        $multiplier = (int) $this->option('multiplier');
        $from = $this->option('from') ?? '2019-01-01';
        $toOption = $this->option('to');
        $to = ($toOption === 'null' || $toOption === null)
            ? now()->toDateString()
            : $toOption;
        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch');
        $sleep = (int) $this->option('sleep');

        $logger = Log::channel('ingest');
        $logger->info("🚀 [LEGACY] Starting Polygon price history ingestion", compact('resolution', 'from', 'to', 'limit', 'batchSize', 'sleep'));

        $this->info("📈 [LEGACY] Starting Polygon ticker price history ingestion...");
        $tickersQuery = Ticker::orderBy('id')->select('id', 'ticker');

        if ($limit > 0) {
            $tickersQuery->limit($limit);
        }

        $totalTickers = $tickersQuery->count();
        if ($totalTickers === 0) {
            $this->warn("⚠️ No tickers found.");
            return 0;
        }

        $this->info("Processing {$totalTickers} tickers in chunks of {$batchSize}...");

        $bar = $this->output->createProgressBar($totalTickers);
        $bar->start();

        $service = app(PolygonTickerPriceHistoryService::class);
        $chunkCount = 0;

        $tickersQuery->chunk($batchSize, function ($tickers) use (
            $service, $resolution, $multiplier, $from, $to, $sleep, &$chunkCount, $bar, $logger
        ) {
            $chunkCount++;
            $logger->info("Processing chunk #{$chunkCount} (".count($tickers)." tickers)");

            foreach ($tickers as $ticker) {
                try {
                    $service->fetchAndStore($ticker, $from, $to, $resolution, $multiplier);
                    $bar->advance();
                } catch (\Throwable $e) {
                    $logger->error("❌ Error ingesting {$ticker->ticker}: ".$e->getMessage(), [
                        'ticker_id' => $ticker->id,
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            if ($sleep > 0) {
                $this->newLine();
                $this->info("⏳ Sleeping {$sleep}s before next batch...");
                sleep($sleep);
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("🎯 [LEGACY] All batches completed ({$chunkCount} total).");
        $logger->info("[LEGACY] Polygon ticker price history ingestion completed.", ['total_batches' => $chunkCount]);

        return 0;
    }
}