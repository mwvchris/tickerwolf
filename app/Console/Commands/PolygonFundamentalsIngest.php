<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\IngestTickerFundamentalsJob;
use Throwable;

class PolygonFundamentalsIngest extends Command
{
    protected $signature = 'polygon:fundamentals:ingest
                            {ticker? : Optional specific ticker symbol}
                            {--limit= : Optional page size per API call (omit for provider default)}
                            {--order=desc : Sort order returned by API: asc|desc}
                            {--timeframe= : Optional timeframe: annual|quarterly|ttm|all}
                            {--gte= : filing_date.gte (≥ start date, YYYY-MM-DD)}
                            {--gt= : filing_date.gt (> start date, YYYY-MM-DD)}
                            {--lte= : filing_date.lte (≤ end date, YYYY-MM-DD)}
                            {--lt= : filing_date.lt (< end date, YYYY-MM-DD)}
                            {--batch=200 : Number of tickers per batch}
                            {--sleep=5 : Seconds to pause between batch dispatches}
                            {--sync : Run jobs synchronously for debugging (bypass queue)}';

    protected $description = 'Queue and batch ingest of Polygon Fundamentals (financials) for one or all tickers, across multiple timeframes, with optional filing_date filters.';

    public function handle(): int
    {
        $ticker    = $this->argument('ticker');
        $limit     = $this->option('limit');
        $order     = $this->option('order') ?: 'desc';
        $timeframe = $this->option('timeframe');
        $batchSize = (int) $this->option('batch');
        $sleep     = (int) $this->option('sleep');
        $syncMode  = (bool) $this->option('sync');

        // Determine timeframes to process
        $timeframes = $this->resolveTimeframes($timeframe);

        // Collect all ingestion options, including date filters
        $options = array_filter([
            'limit' => $limit !== null ? (int) $limit : null,
            'order' => $order,
            'filing_date.gte' => $this->option('gte'),
            'filing_date.gt'  => $this->option('gt'),
            'filing_date.lte' => $this->option('lte'),
            'filing_date.lt'  => $this->option('lt'),
        ], fn($v) => $v !== null);

        Log::channel('ingest')->info('🚀 Starting fundamentals ingest command', [
            'ticker' => $ticker,
            'timeframes' => $timeframes,
            'options' => $options,
            'mode' => $syncMode ? 'sync' : 'queued',
        ]);

        if ($ticker) {
            return $this->handleSingleTicker($ticker, $options, $timeframes, $syncMode);
        }

        return $this->handleBatchIngestion($options, $timeframes, $batchSize, $sleep, $syncMode);
    }

    protected function resolveTimeframes(?string $timeframe): array
    {
        if ($timeframe === 'all' || $timeframe === null) {
            return ['quarterly', 'annual', 'ttm'];
        }

        $allowed = ['quarterly', 'annual', 'ttm'];
        if (!in_array($timeframe, $allowed, true)) {
            $this->error("❌ Invalid timeframe: {$timeframe}. Allowed: " . implode(', ', $allowed) . ", or 'all'");
            exit(self::FAILURE);
        }

        return [$timeframe];
    }

    protected function handleSingleTicker(string $ticker, array $options, array $timeframes, bool $syncMode = false): int
    {
        $symbol = strtoupper(trim($ticker));
        $this->info("📘 Queuing fundamentals ingestion for {$symbol}...");
        Log::channel('ingest')->info("📘 Queuing single-ticker fundamentals ingestion", [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
            'options' => $options,
        ]);

        $tickerModel = Ticker::where('ticker', $symbol)->first();
        if (!$tickerModel) {
            $this->error("Ticker {$symbol} not found.");
            Log::channel('ingest')->warning("⚠️ Ticker not found", ['symbol' => $symbol]);
            return self::FAILURE;
        }

        try {
            foreach ($timeframes as $tf) {
                $opts = array_merge($options, ['timeframe' => $tf]);
                Log::channel('ingest')->info("⏳ Ingesting {$tf} data for {$symbol}");

                if ($syncMode) {
                    Log::channel('ingest')->info("⚙️ Running in SYNC mode for {$symbol} [{$tf}]");
                    (new IngestTickerFundamentalsJob($tickerModel->id, $opts))->handle(
                        app(\App\Services\PolygonFundamentalsService::class)
                    );
                } else {
                    IngestTickerFundamentalsJob::dispatch($tickerModel->id, $opts)
                        ->onConnection('database')
                        ->onQueue('default');
                }

                $this->info("✅ Fundamentals job dispatched for {$symbol} [{$tf}]");
                Log::channel('ingest')->info("✅ Job dispatched successfully", [
                    'symbol' => $symbol,
                    'timeframe' => $tf,
                    'mode' => $syncMode ? 'sync' : 'queued',
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ Failed dispatching fundamentals job", [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $this->error("❌ Dispatch failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function handleBatchIngestion(array $options, array $timeframes, int $batchSize, int $sleep, bool $syncMode = false): int
    {
        $this->info("🔎 Selecting active tickers...");
        Log::channel('ingest')->info("🔎 Selecting active tickers for batch ingestion");

        $tickers = Ticker::select('id', 'ticker')
            ->where('active', true)
            ->orderBy('id')
            ->cursor();

        $count = $tickers->count();
        if ($count === 0) {
            $this->warn('No active tickers found.');
            Log::channel('ingest')->warning("⚠️ No active tickers found");
            return self::SUCCESS;
        }

        $this->info("🧱 Dispatching fundamentals ingestion for {$count} tickers ({$batchSize}/batch) across " . implode(', ', $timeframes));
        Log::channel('ingest')->info("🧱 Beginning batch dispatch", [
            'total_tickers' => $count,
            'batch_size' => $batchSize,
            'timeframes' => $timeframes,
            'mode' => $syncMode ? 'sync' : 'queued',
        ]);

        $batchNumber = 0;
        $dispatched = 0;
        $chunk = [];

        foreach ($tickers as $t) {
            foreach ($timeframes as $tf) {
                $chunk[] = new IngestTickerFundamentalsJob($t->id, array_merge($options, ['timeframe' => $tf]));
                $dispatched++;
            }

            if (count($chunk) >= $batchSize) {
                $batchNumber++;
                $this->dispatchChunk($chunk, $batchNumber, $sleep, $syncMode);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $batchNumber++;
            $this->dispatchChunk($chunk, $batchNumber, $sleep, $syncMode);
        }

        $this->info("✅ Queued {$dispatched} jobs across {$batchNumber} batches.");
        Log::channel('ingest')->info("✅ Finished queuing fundamentals batches", [
            'batches' => $batchNumber,
            'total_dispatched' => $dispatched,
        ]);

        return self::SUCCESS;
    }

    protected function dispatchChunk(array $jobs, int $batchNumber, int $sleep, bool $syncMode = false): void
    {
        try {
            if ($syncMode) {
                Log::channel('ingest')->info("⚙️ Running batch #{$batchNumber} in SYNC mode", [
                    'job_count' => count($jobs),
                ]);
                foreach ($jobs as $job) {
                    $job->handle(app(\App\Services\PolygonFundamentalsService::class));
                }
                return;
            }

            Log::channel('ingest')->info("📦 Dispatching batch #{$batchNumber}", [
                'job_count' => count($jobs),
            ]);

            DB::connection()->reconnect();

            $batch = Bus::batch($jobs)
                ->name("PolygonFundamentals Batch #{$batchNumber}")
                ->onConnection('database')
                ->onQueue('default')
                ->then(fn() => Log::channel('ingest')->info("✅ Batch #{$batchNumber} complete"))
                ->catch(fn(Throwable $e) => Log::channel('ingest')->error("❌ Batch #{$batchNumber} failed", ['error' => $e->getMessage()]))
                ->dispatch();

            $this->info("✅ Dispatched batch #{$batchNumber} ({$batch->totalJobs} jobs)");
            Log::channel('ingest')->info("🧩 Batch dispatched successfully", [
                'batch_number' => $batchNumber,
                'total_jobs' => $batch->totalJobs,
            ]);

            if ($sleep > 0) {
                Log::channel('ingest')->info("😴 Sleeping for {$sleep}s before next batch");
                sleep($sleep);
            }
        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ Error dispatching fundamentals batch", [
                'batch_number' => $batchNumber,
                'error' => $e->getMessage(),
            ]);
            $this->error("❌ Failed to dispatch batch #{$batchNumber}: {$e->getMessage()}");
        }
    }
}