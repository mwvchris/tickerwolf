<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\ComputeTickerIndicatorsJob;
use Throwable;

/**
 * Class TickersComputeIndicators
 *
 * Laravel Artisan command to compute and persist configured technical indicators
 * for one or more tickers using locally stored OHLCV data.
 *
 * This command forms the backbone of the hybrid computation system — populating
 * the `ticker_indicators` table and optionally triggering JSON-based feature
 * snapshots (in `ticker_feature_snapshots`).
 *
 * Responsibilities:
 *  - Select tickers to process (by list or all active).
 *  - Determine which indicator modules to run (from config or CLI flag).
 *  - Create and dispatch `ComputeTickerIndicatorsJob` jobs in batches.
 *  - Orchestrate parallel computation safely via Laravel’s Bus::batch().
 *
 * Example Usage:
 *   php artisan tickers:compute-indicators --from=2019-01-01 --to=2025-01-01 --batch=500 --sleep=1
 *
 *   # Only recompute for specific tickers
 *   php artisan tickers:compute-indicators --tickers=AAPL,MSFT,NVDA --indicators=ema,rsi,macd
 *
 * Flags:
 *   --tickers          Comma-separated list (default: all active)
 *   --indicators       Comma-separated list (default: config('indicators.storage.ticker_indicators'))
 *   --from,--to        Optional date filters
 *   --batch            Number of tickers per job batch (default: 100)
 *   --sleep            Seconds to sleep between batch dispatches (default: 0)
 *   --include-inactive Include inactive tickers
 *   --no-snapshots     Disable writing to ticker_feature_snapshots
 */
class TickersComputeIndicators extends Command
{
    protected $signature = 'tickers:compute-indicators
        {--tickers= : Comma-separated ticker list (default all active tickers)}
        {--indicators= : Comma-separated indicator list}
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD)}
        {--batch=100 : Tickers per job batch}
        {--sleep=0 : Seconds to sleep between batches}
        {--include-inactive : Include inactive tickers}
        {--no-snapshots : Skip writing JSON snapshots (ticker_feature_snapshots)}
    ';

    protected $description = 'Compute and persist configured technical indicators for selected tickers.';

    public function handle(): int
    {
        $tickersOpt       = $this->option('tickers');
        $indicatorsOpt    = $this->option('indicators');
        $from             = $this->option('from');
        $to               = $this->option('to');
        $batchSize        = (int)$this->option('batch');
        $sleepSeconds     = (int)$this->option('sleep');
        $includeInactive  = (bool)$this->option('include-inactive');
        $writeSnapshots   = ! $this->option('no-snapshots');

        Log::channel('ingest')->info("▶️ tickers:compute-indicators starting", [
            'tickers' => $tickersOpt,
            'indicators' => $indicatorsOpt,
            'from' => $from,
            'to' => $to,
            'snapshots' => $writeSnapshots ? 'enabled' : 'disabled',
        ]);

        try {
            // ---------------------------------------------------------------------
            // (1) Resolve ticker universe
            // ---------------------------------------------------------------------
            if ($tickersOpt) {
                $symbols = array_values(array_filter(array_map('trim', explode(',', $tickersOpt))));
                $tickerIds = Ticker::whereIn('ticker', $symbols)->pluck('id')->all();
                $this->info("Selected " . count($tickerIds) . " tickers from CLI argument.");
            } else {
                $query = Ticker::query();
                if (! $includeInactive) {
                    $query->where('active', true);
                }
                $tickerIds = $query->pluck('id')->all();
                $this->info("Selected " . count($tickerIds) . " tickers from active universe.");
            }

            if (empty($tickerIds)) {
                $this->warn('No tickers found to process.');
                return self::SUCCESS;
            }

            // ---------------------------------------------------------------------
            // (2) Resolve which indicators to compute
            // ---------------------------------------------------------------------
            $configured = config('indicators.storage.ticker_indicators', []);
            $indicators = $indicatorsOpt
                ? array_values(array_filter(array_map('trim', explode(',', $indicatorsOpt))))
                : $configured;

            if (empty($indicators)) {
                $this->warn('No indicators configured or provided. Aborting.');
                return self::SUCCESS;
            }

            // ---------------------------------------------------------------------
            // (3) Prepare date range and job batching
            // ---------------------------------------------------------------------
            $range = [];
            if ($from) $range['from'] = $from;
            if ($to)   $range['to']   = $to;

            $chunks = array_chunk($tickerIds, max(1, $batchSize));
            $batchJobs = [];

            foreach ($chunks as $i => $chunk) {
                $batchJobs[] = new ComputeTickerIndicatorsJob(
                    tickerIds: $chunk,
                    indicators: $indicators,
                    range: $range,
                    params: [],
                    writeSnapshots: $writeSnapshots
                );

                $this->info("Queued chunk " . ($i + 1) . "/" . count($chunks) . " (" . count($chunk) . " tickers)");

                if ($sleepSeconds > 0 && $i < count($chunks) - 1) {
                    $this->line("Sleeping {$sleepSeconds}s before next batch...");
                    sleep($sleepSeconds);
                }
            }

            // ---------------------------------------------------------------------
            // (4) Dispatch job batch to queue
            // ---------------------------------------------------------------------
            $batch = Bus::batch($batchJobs)
                ->name('TickersComputeIndicators [' . now()->toDateTimeString() . ']')
                ->allowFailures()
                ->dispatch();

            $this->info("🚀 Dispatched compute batch id={$batch->id} ({$batch->totalJobs} jobs)");
            Log::channel('ingest')->info("✅ tickers:compute-indicators batch dispatched", [
                'batch_id' => $batch->id,
                'total_jobs' => $batch->totalJobs,
                'snapshots' => $writeSnapshots,
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ tickers:compute-indicators failed", [
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 1000),
            ]);
            throw $e;
        }
    }
}