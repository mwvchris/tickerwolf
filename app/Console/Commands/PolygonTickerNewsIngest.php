<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\IngestTickerNewsJob;
use App\Services\BatchMonitorService;
use Throwable;

/**
 * ============================================================================
 *  polygon:ticker-news:ingest
 *  (v2.0 — Batched, Monitored, Incremental-Aware News Ingestion)
 * ============================================================================
 *
 *  Queue Polygon/Massive.io news ingestion jobs for:
 *    • A single ticker (via argument) → one job
 *    • The active ticker universe     → many jobs, chunked into Bus::batch-es
 *
 *  The per-ticker incremental behavior (“fetch only newer articles”) lives in:
 *      • IngestTickerNewsJob
 *      • PolygonTickerNewsService
 *
 *  THIS COMMAND decides:
 *      • which tickers get jobs
 *      • how many recent articles to request (--limit)
 *      • batching strategy (--batch, --sleep)
 *      • queue + BatchMonitor + logging
 *
 * ============================================================================
 */
class PolygonTickerNewsIngest extends Command
{
    /**
     * Artisan signature.
     */
    protected $signature = 'polygon:ticker-news:ingest
                            {ticker? : Optional specific, case-sensitive ticker symbol}
                            {--limit=200 : Approximate max news items per ticker this run}
                            {--batch=400 : Number of tickers per Bus::batch dispatch}
                            {--sleep=2 : Seconds to pause between batch dispatches}';

    /**
     * Description.
     */
    protected $description = 'Queue and batch ingest of latest / incremental news items for one or all tickers from Polygon/Massive.io';

    /**
     * 🔒 Ensure limit is always a safe, serializable scalar.
     */
    protected function sanitizeLimit($limit): int
    {
        return is_numeric($limit) ? (int)$limit : 200;
    }

    /**
     * Entry point.
     */
    public function handle(): int
    {
        $tickerArg = $this->argument('ticker');
        $ticker    = $tickerArg !== null ? trim($tickerArg) : null;

        // Ensure limit is always scalar
        $limit     = $this->sanitizeLimit($this->option('limit'));
        $batchSize = (int)$this->option('batch');
        $sleep     = (int)$this->option('sleep');

        if ($batchSize <= 0) $batchSize = 400;
        if ($sleep < 0) $sleep = 0;

        $logger = Log::channel('ingest');

        $logger->info('📰 polygon:ticker-news:ingest starting', [
            'ticker'     => $ticker ?? 'ALL_ACTIVE',
            'limit'      => $limit,
            'batch_size' => $batchSize,
            'sleep'      => $sleep,
        ]);

        if (!empty($ticker)) {
            return $this->handleSingleTicker($ticker, $limit, $logger);
        }

        return $this->handleBatchIngestion($limit, $batchSize, $sleep, $logger);
    }

    /**
     * Handle single-ticker mode (no Bus::batch needed).
     */
    protected function handleSingleTicker(string $ticker, int $limit, $logger): int
    {
        $symbol = trim($ticker);

        $this->info("📰 Queuing news ingestion for [{$symbol}]...");
        $logger->info('📰 Queuing news ingestion for single ticker', [
            'ticker' => $symbol,
            'limit'  => $limit,
        ]);

        $tickerModel = Ticker::where('ticker', $symbol)->first();

        if (!$tickerModel) {
            $this->error("❌ Ticker {$symbol} not found.");
            $logger->warning('⚠️ Ticker not found for news ingest', ['ticker' => $symbol]);
            return self::FAILURE;
        }

        try {
            IngestTickerNewsJob::dispatch($tickerModel->id, $limit)
                ->onConnection('database')
                ->onQueue('default');

            $this->info("✅ Dispatched news job for {$symbol}");
            $logger->info('✅ Dispatched news job for single ticker', [
                'ticker_id' => $tickerModel->id,
                'ticker'    => $symbol,
                'limit'     => $limit,
            ]);

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error("❌ Failed to dispatch news job for {$symbol}: {$e->getMessage()}");

            $logger->error('❌ Exception while dispatching single-ticker news job', [
                'ticker' => $symbol,
                'limit'  => $limit,
                'error'  => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Handle full-universe ingestion.
     */
    protected function handleBatchIngestion(int $limit, int $batchSize, int $sleep, $logger): int
    {
        $this->info("🔎 Selecting active tickers for news ingestion...");

        $baseQuery = Ticker::query()
            ->select('id', 'ticker')
            ->where('active', true)
            ->orderBy('id');

        // IMPORTANT: cursor() does not support ->count()
        $totalTickers = (clone $baseQuery)->count();

        if ($totalTickers === 0) {
            $this->warn('⚠️ No active tickers found. Nothing to ingest.');
            $logger->warning('⚠️ No active tickers found for news ingestion');
            return self::SUCCESS;
        }

        $this->info("🧱 Dispatching ticker news ingestion batches (batch size: {$batchSize})...");
        $this->line("   Total active tickers : {$totalTickers}");
        $this->line("   Limit per ticker     : {$limit}");
        $this->newLine();

        $logger->info('🧱 Dispatching ticker news ingestion batches', [
            'total_tickers' => $totalTickers,
            'batch_size'    => $batchSize,
            'limit'         => $limit,
            'sleep'         => $sleep,
        ]);

        // Top-level batch monitor for UX
        BatchMonitorService::createBatch('PolygonTickerNews', $totalTickers);

        $chunk         = [];
        $batchNumber   = 0;
        $queuedTickers = 0;

        foreach ($baseQuery->cursor() as $ticker) {
            // Only scalars passed into jobs → serialization-safe
            $chunk[] = new IngestTickerNewsJob((int)$ticker->id, (int)$limit);
            $queuedTickers++;

            if (count($chunk) >= $batchSize) {
                $batchNumber++;
                $this->dispatchChunk($chunk, $batchNumber, $sleep, $logger);
                $chunk = [];
            }
        }

        // Flush remaining jobs (last partial batch)
        if (!empty($chunk)) {
            $batchNumber++;
            $this->dispatchChunk($chunk, $batchNumber, $sleep, $logger);
        }

        $this->info("✅ Queued {$queuedTickers} tickers across {$batchNumber} batches.");
        $logger->info('🏁 polygon:ticker-news:ingest batch enqueue complete', [
            'queued_tickers' => $queuedTickers,
            'batches'        => $batchNumber,
            'limit'          => $limit,
        ]);

        return self::SUCCESS;
    }

    /**
     * Dispatch one Bus::batch of jobs.
     *
     * @param array<IngestTickerNewsJob> $jobs
     */
    protected function dispatchChunk(array $jobs, int $batchNumber, int $sleep, $logger): void
    {
        if (empty($jobs)) return;

        $jobCount = count($jobs);

        try {
            $logger->info('📦 Dispatching PolygonNews batch', [
                'batch_number' => $batchNumber,
                'jobs'         => $jobCount,
            ]);

            $batch = Bus::batch($jobs)
                ->name("PolygonNews Batch #{$batchNumber}")
                ->onConnection('database')
                ->onQueue('default')
                ->dispatch();

            $this->info("✅ Dispatched news batch #{$batchNumber} ({$batch->totalJobs} jobs)");

            if ($sleep > 0) {
                $this->info("⏳ Sleeping {$sleep}s before next news batch...");
                sleep($sleep);
            }

        } catch (Throwable $e) {
            $this->error("❌ Failed to dispatch news batch #{$batchNumber}: {$e->getMessage()}");

            $logger->error('❌ Exception while dispatching PolygonNews batch', [
                'batch_number' => $batchNumber,
                'jobs'         => $jobCount,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}