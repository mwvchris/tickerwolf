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
 * Supports dry-run preview mode for verification before actual database writes.
 *
 * Example usage:
 *   php artisan tickers:build-snapshots --tickers=AAPL,MSFT --from=2022-01-01 --to=2024-12-31
 *   php artisan tickers:build-snapshots --batch=250 --sleep=2 --include-inactive
 *   php artisan tickers:build-snapshots --tickers=AAPL --from=2024-01-01 --to=2024-12-31 --preview
 */
class TickersBuildSnapshots extends Command
{
    protected $signature = 'tickers:build-snapshots
        {--tickers= : Comma-separated list (default: all active tickers)}
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD)}
        {--batch=100 : Number of tickers per batch job}
        {--sleep=0 : Seconds to sleep between batch dispatches}
        {--include-inactive : Include inactive tickers in selection}
        {--preview : Dry-run mode (logs output, skips database writes)}
    ';

    protected $description = 'Aggregate daily feature snapshots into JSON analytics vectors for AI and data pipelines.';

    public function handle(): int
    {
        $tickersOpt       = trim((string) $this->option('tickers'));
        $from             = $this->option('from');
        $to               = $this->option('to');
        $batchSize        = max(1, (int) $this->option('batch'));
        $sleepSeconds     = (int) $this->option('sleep');
        $includeInactive  = (bool) $this->option('include-inactive');
        $preview          = (bool) $this->option('preview');

        Log::channel('ingest')->info('▶️ tickers:build-snapshots starting', [
            'tickers_option' => $tickersOpt,
            'from'           => $from,
            'to'             => $to,
            'batch'          => $batchSize,
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
                $this->info("🌎 Selected " . count($tickerIds) . " active ticker(s) from database.");
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
            // 3️⃣ Create batched snapshot jobs
            // ---------------------------------------------------------------------
            $chunks = array_chunk($tickerIds, $batchSize);
            $jobs = [];

            foreach ($chunks as $i => $chunk) {
                $jobs[] = new BuildTickerSnapshotJob($chunk, $range, [], $preview);
                $this->info("📦 Queued batch " . ($i + 1) . "/" . count($chunks) . " (" . count($chunk) . " tickers)");

                if ($sleepSeconds > 0 && $i < count($chunks) - 1) {
                    $this->line("⏸ Sleeping {$sleepSeconds}s before next batch...");
                    sleep($sleepSeconds);
                }
            }

            // ---------------------------------------------------------------------
            // 4️⃣ Dispatch batched jobs
            // ---------------------------------------------------------------------
            $batch = Bus::batch($jobs)
                ->name('TickersBuildSnapshots [' . now()->toDateTimeString() . ']')
                ->allowFailures()
                ->dispatch();

            $this->info("🚀 Dispatched snapshot batch id={$batch->id} ({$batch->totalJobs} jobs)");
            Log::channel('ingest')->info('✅ tickers:build-snapshots batch dispatched', [
                'batch_id'    => $batch->id,
                'total_jobs'  => $batch->totalJobs,
                'tickers'     => count($tickerIds),
                'preview'     => $preview,
            ]);

            // ---------------------------------------------------------------------
            // 5️⃣ Final confirmation
            // ---------------------------------------------------------------------
            if ($preview) {
                $this->comment("💡 Preview mode enabled — no database writes performed.");
            } else {
                $this->info("✅ Snapshot jobs running asynchronously in background (batch ID: {$batch->id})");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('ingest')->error('❌ tickers:build-snapshots failed', [
                'message' => $e->getMessage(),
                'trace'   => substr($e->getTraceAsString(), 0, 1000),
            ]);

            $this->error("❌ Command failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}