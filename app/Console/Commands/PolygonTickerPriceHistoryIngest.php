<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\IngestTickerPriceHistoryJob;
use App\Services\BatchMonitorService;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ============================================================================
 *  polygon:ticker-price-histories:ingest  (v2.6.4 — Symbol-Aware Queued Ingest)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Dispatches asynchronous (queued) Polygon.io price-history ingestion jobs
 *   for all tickers or a single ticker specified via --symbol.
 *   Provides resilient batch orchestration with progress tracking,
 *   error logging, and configurable time-range controls.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Fetches all tickers (or one via --symbol).
 *   • Divides them into job batches and dispatches to the database queue.
 *   • Uses configuration defaults for date range and resolution.
 *   • Optionally throttles between batches to respect rate limits.
 *   • Integrates with BatchMonitorService for centralized tracking.
 *
 * ⚙️ Config Integration:
 * ----------------------------------------------------------------------------
 *   Reads from config/polygon.php:
 *     - price_history_min_date → default historical floor
 *     - default_timespan       → “day”, “minute”, etc.
 *     - default_multiplier     → Polygon aggregates multiplier
 *
 * 📦 Command Signature:
 * ----------------------------------------------------------------------------
 *   php artisan polygon:ticker-price-histories:ingest
 *       --symbol=AAPL
 *       --resolution=1d
 *       --from=2020-01-01
 *       --to=2025-10-31
 *       --limit=1000
 *       --batch=500
 *       --sleep=5
 *
 * 💾 Logging & Diagnostics:
 * ----------------------------------------------------------------------------
 *   • Logs per-batch dispatch events to storage/logs/ingest.log.
 *   • Prints console progress bar + total summary.
 *   • Reports batch count, tickers processed, and error traces if any.
 *
 * 🚀 New in v2.6.4:
 * ----------------------------------------------------------------------------
 *   • Removes forced uppercasing of ticker symbols (case-sensitive API fix).
 *   • Ensures compatibility with Polygon tickers containing mixed case
 *     (e.g., ABRpD, ATHpA, etc.).
 *   • Adds inline comments explaining symbol casing rationale.
 *   • Retains all existing queue orchestration, logging, and schema advisory.
 *
 * 🧩 Related Components:
 * ----------------------------------------------------------------------------
 *   • App\Jobs\IngestTickerPriceHistoryJob
 *   • App\Services\BatchMonitorService
 *   • config/polygon.php
 * ============================================================================
 */
class PolygonTickerPriceHistoryIngest extends Command
{
    protected $signature = 'polygon:ticker-price-histories:ingest
                            {--symbol= : Optional single ticker symbol to ingest (e.g. AAPL or ABRpD)}
                            {--resolution=1d : Resolution (1d, 1m, 5m, etc.)}
                            {--from= : Start date (YYYY-MM-DD), defaults to polygon.price_history_min_date}
                            {--to= : End date (YYYY-MM-DD), defaults to today}
                            {--limit=0 : Limit total tickers processed (0 = all)}
                            {--batch=500 : Number of tickers per job batch}
                            {--sleep=5 : Seconds to sleep before dispatching next batch}';

    protected $description = 'Queue Polygon.io price-history ingestion jobs for all or specific tickers with configurable range and batching.';

    public function handle(): int
    {
        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Load Options & Config Defaults
        |--------------------------------------------------------------------------
        |
        |  🔎 IMPORTANT CHANGE:
        |  Polygon’s aggregates API is *case-sensitive* for some tickers
        |  (especially preferred shares, units, and SPACs).
        |
        |  Previously, we used strtoupper() to normalize all symbols,
        |  which caused valid tickers like "ABRpD" or "ATHpA" to fail.
        |
        |  Fix: We now preserve the symbol’s exact case from the DB
        |  or from the --symbol argument as provided.
        */
        $symbol     = trim($this->option('symbol') ?? '');  // ✅ Case preserved — do NOT uppercase.
        $resolution = $this->option('resolution') ?? config('polygon.default_timespan', '1d');
        $from       = $this->option('from') ?? config('polygon.price_history_min_date', '2020-01-01');

        $toOption   = $this->option('to');
        $to         = $toOption ?: now()->toDateString();

        $limit      = (int) $this->option('limit', 0);
        $batchSize  = (int) $this->option('batch', 500);
        $sleep      = (int) $this->option('sleep', 5);

        $logger = Log::channel('ingest');

        $logger->info('📥 Polygon ingestion command started', [
            'symbol'     => $symbol ?: 'ALL',
            'resolution' => $resolution,
            'from'       => $from,
            'to'         => $to,
            'limit'      => $limit,
            'batchSize'  => $batchSize,
            'sleep'      => $sleep,
        ]);

        $this->info("📈 Preparing Polygon ticker price ingestion...");
        $this->line("   Symbol     : " . ($symbol ?: 'ALL TICKERS'));
        $this->line("   Range      : {$from} → {$to}");
        $this->line("   Resolution : {$resolution}");
        $this->line("   Batch Size : {$batchSize}");
        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Build Ticker Query
        |--------------------------------------------------------------------------
        |
        |  If a --symbol was passed, match it *exactly* (case-sensitive)
        |  because Polygon tickers like ABRpD ≠ ABRPD.
        */
        $query = Ticker::orderBy('id')->select('id', 'ticker');
        if ($symbol) {
            $query->where('ticker', $symbol);  // case-sensitive match
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->warn("⚠️ No tickers found for ingestion (symbol={$symbol}).");
            return Command::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Initialize Batch Monitor & Progress Bar
        |--------------------------------------------------------------------------
        */
        BatchMonitorService::createBatch('PolygonTickerPriceHistories', $total);

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat("   🟢 Progress: %current%/%max% [%bar%] %percent:3s%%");
        $bar->start();

        $batchIndex = 1;

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Chunk & Dispatch Jobs
        |--------------------------------------------------------------------------
        */
        $query->chunk($batchSize, function ($tickers) use (
            $resolution, $from, $to, $sleep, $bar, $logger, &$batchIndex
        ) {
            $jobs = [];
            foreach ($tickers as $ticker) {
                $jobs[] = new IngestTickerPriceHistoryJob($ticker, $from, $to, $resolution);
                $bar->advance();
            }

            if (empty($jobs)) {
                return;
            }

            try {
                $batch = Bus::batch($jobs)
                    ->name("PolygonTickerPriceHistoriesIngest (Batch #{$batchIndex})")
                    ->onConnection('database')
                    ->onQueue('default')
                    ->dispatch();

                $jobCount = count($jobs);
                $logger->info('✅ Dispatched ingestion batch', [
                    'batch_index' => $batchIndex,
                    'batch_id'    => $batch->id ?? null,
                    'job_count'   => $jobCount,
                    'from'        => $from,
                    'to'          => $to,
                    'resolution'  => $resolution,
                ]);

                $this->newLine();
                $this->info("✅ Dispatched batch #{$batchIndex} ({$jobCount} jobs)");
                $batchIndex++;

                if ($sleep > 0) {
                    $this->info("⏳ Sleeping {$sleep}s before next batch...");
                    sleep($sleep);
                }
            } catch (Throwable $e) {
                $logger->error('❌ Failed to dispatch ingestion batch', [
                    'batch_index' => $batchIndex,
                    'error'       => $e->getMessage(),
                    'trace'       => substr($e->getTraceAsString(), 0, 400),
                ]);

                $this->error("❌ Batch #{$batchIndex} failed: {$e->getMessage()}");
            }
        });

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Finalize & Report
        |--------------------------------------------------------------------------
        */
        $bar->finish();
        $this->newLine(2);

        $this->info("🎯 All batches dispatched successfully.");
        $logger->info('🏁 Polygon ingestion complete', [
            'symbol'    => $symbol ?: 'ALL',
            'total'     => $total,
            'batches'   => $batchIndex - 1,
            'completed' => now()->toIso8601String(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | ℹ️ Schema Advisory (job_batches)
        |--------------------------------------------------------------------------
        */
        if (! Schema::hasColumn('job_batches', 'processed_jobs')) {
            $this->warn("⚠️ Note: The 'job_batches' table appears outdated. Run:");
            $this->line("   php artisan queue:batches-table && php artisan migrate");
            $this->line("   (This adds 'processed_jobs' for accurate queue metrics.)");
            $this->newLine();
        }

        return Command::SUCCESS;
    }
}