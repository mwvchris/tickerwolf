<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\IngestTickerPriceHistoryJob;
use App\Services\BatchMonitorService;
use Throwable;

class PolygonTickerPriceHistoryIngest extends Command
{
    protected $signature = 'polygon:ticker-price-histories:ingest
                            {--resolution=1d : Resolution (1d, 1m, 5m, etc.)}
                            {--from=2019-01-01 : Start date (YYYY-MM-DD)}
                            {--to=null : End date (YYYY-MM-DD) or null for today}
                            {--limit=0 : Limit total tickers processed (0 = all)}
                            {--batch=500 : Number of tickers per job batch}
                            {--sleep=5 : Seconds to sleep before dispatch (rate limiting)}';

    protected $description = 'Queue Polygon price history ingestion jobs for all tickers and track via batch monitoring';

    public function handle(): int
    {
        $resolution = $this->option('resolution') ?? '1d';
        $from = $this->option('from') ?? '2019-01-01';
        $toOption = $this->option('to');
        $to = ($toOption === 'null' || $toOption === null)
            ? now()->toDateString()
            : $toOption;

        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch');
        $sleep = (int) $this->option('sleep');

        $logger = Log::channel('ingest');
        $this->info("📈 Preparing to dispatch Polygon ticker price history ingestion...");
        $logger->info('Starting Polygon price histories ingestion', compact('resolution', 'from', 'to', 'limit', 'batchSize', 'sleep'));

        $tickersQuery = Ticker::orderBy('id')->select('id', 'ticker');
        $totalTickers = $tickersQuery->count();

        if ($limit > 0 && $limit < $totalTickers) {
            $tickersQuery->limit($limit);
            $totalTickers = $limit;
        }

        $this->info("Total tickers to process: {$totalTickers}");
        $logger->info("Preparing batch for {$totalTickers} tickers");

        if ($totalTickers === 0) {
            $this->warn("⚠️ No tickers found to ingest.");
            return 0;
        }

        $bar = $this->output->createProgressBar($totalTickers);
        $bar->start();

        // Optional custom batch monitor
        BatchMonitorService::createBatch('PolygonTickerPriceHistories', $totalTickers);

        $batchCount = 1;

        $tickersQuery->chunk($batchSize, function ($tickers) use (
            $resolution, $from, $to, $sleep, $bar, $logger, &$batchCount
        ) {
            $jobs = [];

            foreach ($tickers as $ticker) {
                // Pass the full Ticker model to the job
                $jobs[] = new IngestTickerPriceHistoryJob($ticker, $from, $to);
                $bar->advance();
            }

            if (empty($jobs)) {
                return;
            }

            try {
                $batch = Bus::batch($jobs)
                    ->name("PolygonTickerPriceHistoriesIngest (chunk #{$batchCount})")
                    ->onConnection('database')
                    ->onQueue('default')
                    ->dispatch();

                $jobCount = count($jobs);
                $this->newLine();
                $this->info("✅ Dispatched chunk #{$batchCount} ({$jobCount} jobs): {$batch->id}");

                $logger->info('✅ Dispatched chunk batch', [
                    'batch_number' => $batchCount,
                    'batch_id' => $batch->id,
                    'job_count' => $jobCount,
                ]);

                $batchCount++;

                if ($sleep > 0) {
                    $this->info("⏳ Sleeping {$sleep}s before next dispatch...");
                    sleep($sleep);
                }
            } catch (Throwable $e) {
                $this->error("❌ Failed to dispatch chunk batch #{$batchCount}: {$e->getMessage()}");
                $logger->error('Error dispatching chunk batch', [
                    'batch_number' => $batchCount,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("🎯 All batches dispatched successfully ({$batchCount} total).");
        $logger->info("All ticker ingestion batches dispatched successfully", ['total_batches' => $batchCount]);

        return 0;
    }
}