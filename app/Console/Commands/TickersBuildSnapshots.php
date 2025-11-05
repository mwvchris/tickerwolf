<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\BuildTickerSnapshotJob;
use Throwable;

/**
 * Class TickersBuildSnapshots
 *
 * High-level orchestration command for generating daily or weekly
 * JSON feature snapshots across all or selected tickers.
 *
 * Snapshots combine:
 *  - Core indicators from ticker_indicators
 *  - Derived analytics (Sharpe Ratio, Beta, Volatility, etc.)
 *  - Aggregated feature vectors for AI and analytics pipelines
 *
 * This command is optimized for automation — safe to schedule as a nightly cron
 * or run under Laravel’s task scheduler without manual worker management.
 *
 * Enhancements over previous version:
 *  ✅ One ticker = one job → maximum concurrency, granular retries
 *  ✅ Smart chunked dispatching to control queue load
 *  ✅ Built-in performance & memory logging
 *  ✅ Seamless integration with `php artisan schedule:run`
 *
 * Example usage:
 *   php artisan tickers:build-snapshots --tickers=AAPL,MSFT --from=2022-01-01 --to=2024-12-31
 *   php artisan tickers:build-snapshots --batch=500 --sleep=2 --include-inactive
 *   php artisan tickers:build-snapshots --preview
 */
class TickersBuildSnapshots extends Command
{
    protected $signature = 'tickers:build-snapshots
        {--tickers= : Comma-separated list (default: all active tickers)}
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD)}
        {--batch=500 : Number of jobs to queue per dispatch chunk}
        {--sleep=1 : Seconds to sleep between batch dispatches}
        {--include-inactive : Include inactive tickers in selection}
        {--preview : Dry-run mode (logs output, skips database writes)}
    ';

    protected $description = 'Aggregate daily feature snapshots into JSON analytics vectors for AI and data pipelines.';

    public function handle(): int
    {
        $tickersOpt      = trim((string) $this->option('tickers'));
        $from            = $this->option('from');
        $to              = $this->option('to');
        $batchSize       = max(1, (int) $this->option('batch'));      // how many jobs to enqueue per chunk
        $sleepSeconds    = (int) $this->option('sleep');
        $includeInactive = (bool) $this->option('include-inactive');
        $preview         = (bool) $this->option('preview');

        $startTime = microtime(true);

        Log::channel('ingest')->info('▶️ tickers:build-snapshots starting', [
            'tickers_option' => $tickersOpt,
            'from'           => $from,
            'to'             => $to,
            'batch'          => $batchSize,
            'sleep'          => $sleepSeconds,
            'preview'        => $preview,
        ]);

        try {
            // ---------------------------------------------------------------------
            // 1️⃣ Resolve tickers to process
            // ---------------------------------------------------------------------
            if (!empty($tickersOpt)) {
                $symbols = array_values(array_filter(array_map('trim', explode(',', $tickersOpt))));
                $tickerIds = Ticker::query()
                    ->whereIn('ticker', $symbols)
                    ->pluck('id')
                    ->all();

                $this->info("🧩 Selected " . count($tickerIds) . " ticker(s) by symbol: " . implode(', ', $symbols));
            } else {
                $q = Ticker::query();
                if (!$includeInactive) {
                    $q->where('active', true);
                }

                $tickerIds = $q->pluck('id')->all();
                $this->info("🌎 Selected " . count($tickerIds) . " ticker(s) from database (" . ($includeInactive ? 'all' : 'active only') . ").");
            }

            if (empty($tickerIds)) {
                $this->warn('⚠️ No tickers found to process.');
                Log::channel('ingest')->warning('⚠️ tickers:build-snapshots — no tickers matched selection criteria', [
                    'tickers_option' => $tickersOpt,
                    'include_inactive' => $includeInactive,
                ]);
                return self::SUCCESS;
            }

            Log::channel('ingest')->info('🎯 Snapshot build target tickers resolved', [
                'count'   => count($tickerIds),
                'symbols' => $tickersOpt ?: '[all active]',
            ]);

            // ---------------------------------------------------------------------
            // 2️⃣ Define processing range
            // ---------------------------------------------------------------------
            $range = [];
            if ($from) $range['from'] = $from;
            if ($to)   $range['to']   = $to;

            // ---------------------------------------------------------------------
            // 3️⃣ Build one job per ticker
            // ---------------------------------------------------------------------
            $allJobs = [];
            foreach ($tickerIds as $tickerId) {
                $allJobs[] = new BuildTickerSnapshotJob([$tickerId], $range, [], $preview);
            }

            $this->info("🧮 Prepared " . count($allJobs) . " individual snapshot jobs for dispatch...");

            // ---------------------------------------------------------------------
            // 4️⃣ Dispatch jobs in controlled batches to avoid queue overload
            // ---------------------------------------------------------------------
            $chunks = array_chunk($allJobs, $batchSize);
            $totalBatches = count($chunks);
            $dispatchedBatches = 0;

            foreach ($chunks as $i => $jobsChunk) {
                $batch = Bus::batch($jobsChunk)
                    ->name('TickersBuildSnapshots [' . now()->toDateTimeString() . '] chunk ' . ($i + 1))
                    ->allowFailures()
                    ->dispatch();

                $dispatchedBatches++;

                $this->info("🚀 Dispatched batch " . ($i + 1) . "/{$totalBatches} — " . count($jobsChunk) . " job(s) (Batch ID: {$batch->id})");

                Log::channel('ingest')->info('📦 Snapshot job batch dispatched', [
                    'batch_index'  => $i + 1,
                    'batch_id'     => $batch->id,
                    'jobs'         => count($jobsChunk),
                    'total_batches'=> $totalBatches,
                    'preview'      => $preview,
                ]);

                // Prevent overwhelming the queue and DB
                if ($sleepSeconds > 0 && $i < $totalBatches - 1) {
                    $this->line("⏸ Sleeping {$sleepSeconds}s before next dispatch...");
                    sleep($sleepSeconds);
                }
            }

            // ---------------------------------------------------------------------
            // 5️⃣ Completion summary
            // ---------------------------------------------------------------------
            $elapsed = round(microtime(true) - $startTime, 2);

            $summary = [
                'tickers_total'     => count($tickerIds),
                'jobs_dispatched'   => count($allJobs),
                'batches_dispatched'=> $dispatchedBatches,
                'range'             => $range ?: '[full]',
                'elapsed_s'         => $elapsed,
                'preview'           => $preview,
                'memory_mb'         => round(memory_get_usage(true) / 1048576, 2),
            ];

            Log::channel('ingest')->info('✅ tickers:build-snapshots completed dispatch', $summary);

            $this->newLine();
            $this->info("🏁 Snapshot dispatch complete in {$elapsed}s — " .
                "{$dispatchedBatches} batch(es), " . count($allJobs) . " job(s) total.");
            $this->line("💾 Memory used: {$summary['memory_mb']} MB");
            if ($preview) {
                $this->comment("💡 Preview mode active — database writes were skipped.");
            } else {
                $this->info("📡 Jobs now running asynchronously in background workers.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('ingest')->error('❌ tickers:build-snapshots failed', [
                'message' => $e->getMessage(),
                'trace'   => substr($e->getTraceAsString(), 0, 1200),
            ]);

            $this->error("❌ Command failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}