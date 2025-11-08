<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\IngestTickerNewsJob;
use App\Services\BatchMonitorService;
use Throwable;

class PolygonTickerNewsIngest extends Command
{
    protected $signature = 'polygon:ticker-news:ingest 
                            {ticker? : Optional specific ticker symbol} 
                            {--limit=50 : Max news items per ticker} 
                            {--batch=200 : Number of tickers per batch}
                            {--sleep=5 : Seconds to pause between batch dispatches}';

    protected $description = 'Queue and batch ingest of latest news items for one or all tickers from Polygon.io';

    public function handle(): int
    {
        $ticker = $this->argument('ticker');
        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch');
        $sleep = (int) $this->option('sleep');

        if ($ticker) {
            return $this->handleSingleTicker($ticker, $limit);
        }

        return $this->handleBatchIngestion($limit, $batchSize, $sleep);
    }

    protected function handleSingleTicker(string $ticker, int $limit): int
    {
        $this->info("📰 Queuing news ingestion for {$ticker}...");
        $tickerModel = Ticker::where('ticker', $ticker)->first();

        if (! $tickerModel) {
            $this->error("Ticker {$ticker} not found.");
            return self::FAILURE;
        }

        IngestTickerNewsJob::dispatch($tickerModel->id, $limit)->onQueue('default');
        $this->info("✅ Dispatched news job for {$ticker}");
        return self::SUCCESS;
    }

    protected function handleBatchIngestion(int $limit, int $batchSize, int $sleep): int
    {
        $tickers = Ticker::select('id', 'ticker')->where('active', true)->orderBy('id')->cursor();

        if ($tickers->count() === 0) {
            $this->warn('No active tickers found.');
            return self::SUCCESS;
        }

        $this->info("🧱 Dispatching ticker news ingestion batches (batch size: {$batchSize})...");
        $logger = Log::channel('ingest');

        $chunk = [];
        $batchNumber = 0;
        $total = 0;

        foreach ($tickers as $t) {
            $chunk[] = new IngestTickerNewsJob($t->id, $limit);
            $total++;

            if (count($chunk) >= $batchSize) {
                $batchNumber++;
                $this->dispatchChunk($chunk, $batchNumber, $sleep, $logger);
                $chunk = [];
            }
        }

        if (! empty($chunk)) {
            $batchNumber++;
            $this->dispatchChunk($chunk, $batchNumber, $sleep, $logger);
        }

        $this->info("✅ Queued {$total} tickers across {$batchNumber} batches.");
        return self::SUCCESS;
    }

    protected function dispatchChunk(array $jobs, int $batchNumber, int $sleep, $logger): void
    {
        try {
            $batch = Bus::batch($jobs)
                ->name("PolygonNews Batch #{$batchNumber}")
                ->onConnection('database')
                ->onQueue('default')
                ->then(fn() => Log::info("✅ PolygonNews Batch #{$batchNumber} complete"))
                ->catch(fn(Throwable $e) => Log::error("❌ PolygonNews Batch #{$batchNumber} failed: {$e->getMessage()}"))
                ->dispatch();

            BatchMonitorService::createBatch("PolygonNews Batch #{$batchNumber}", count($jobs));

            $this->info("✅ Dispatched batch #{$batchNumber} ({$batch->totalJobs} jobs)");
            sleep($sleep);
        } catch (Throwable $e) {
            $logger->error("Error dispatching batch #{$batchNumber}: {$e->getMessage()}");
            $this->error("❌ Failed to dispatch batch #{$batchNumber}: {$e->getMessage()}");
        }
    }
}