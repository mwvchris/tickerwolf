<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\IngestTickerFundamentalsJob;
use Throwable;
use Illuminate\Support\Carbon;

/**
 * ============================================================================
 *  polygon:fundamentals:ingest
 *  (v3.0 — Windowed, Redundant, Skip-Aware Fundamentals Ingestion)
 * ============================================================================
 *
 * 🔧 Purpose:
 * ----------------------------------------------------------------------------
 *   Queues Polygon fundamentals ingestion jobs for:
 *     • A single ticker  (argument: ticker)
 *     • Or the active universe (no ticker argument)
 *
 *   This refactor adds:
 *     ✅ Per-ticker / per-timeframe redundancy window (re-fetch last N days)
 *     ✅ Windowed subjobs (date slices via filing_date.gte / filing_date.lte)
 *     ✅ Smarter batching by JOB COUNT instead of only ticker count
 *     ✅ “Skip already complete” detection when there’s nothing new to pull
 *
 * 🧠 Behavior (High-Level):
 * ----------------------------------------------------------------------------
 *   • Accepts an optional {ticker} argument (single symbol, unchanged).
 *   • Accepts date filters (--gte/--gt/--lte/--lt). If ANY are provided:
 *       → They are honored exactly.
 *       → No redundancy / auto-windowing is applied (you are in full control).
 *
 *   • If NO date filters are provided:
 *       → For each (ticker, timeframe) pair:
 *           1. Find the latest filing_date we’ve stored.
 *           2. Compute a redundancy window:
 *                start_date = max(latest_filing_date - redundancy_days, min_date)
 *              or, if no history yet:
 *                start_date = min_date
 *           3. end_date = today (inclusive).
 *           4. If start_date > end_date → skip (already up-to-date).
 *           5. Split [start_date, end_date] into N windows of size --window days.
 *           6. Create one job per window with filing_date.gte / lte set.
 *
 * ⚙️ Config Integration (config/polygon.php):
 * ----------------------------------------------------------------------------
 *   • fundamentals_min_date          → default historical floor (e.g. '2015-01-01')
 *   • fundamentals_window_days       → default date window size (days) when windowing
 *   • fundamentals_redundancy_days   → default redundancy (days) per ticker/timeframe
 *
 *   All of these can be overridden via CLI flags.
 *
 * 📦 Command Signature (CLI):
 * ----------------------------------------------------------------------------
 *   php artisan polygon:fundamentals:ingest
 *       {ticker?}                      # optional single symbol
 *       --limit=                       # per-API page size (forwarded to Polygon)
 *       --order=desc                   # asc|desc
 *       --timeframe=all                # annual|quarterly|ttm|all
 *       --gte=                         # filing_date.gte (YYYY-MM-DD)
 *       --gt=                          # filing_date.gt  (YYYY-MM-DD)
 *       --lte=                         # filing_date.lte (YYYY-MM-DD)
 *       --lt=                          # filing_date.lt  (YYYY-MM-DD)
 *       --batch=200                    # ≈ max jobs per Bus::batch
 *       --sleep=5                      # seconds between Bus::batch dispatches
 *       --sync                         # run jobs synchronously (debug mode)
 *       --window=365                   # window size (days) for auto date-slicing
 *       --redundancy-days=365          # days to backtrack from last filing_date
 *
 * 💡 Examples:
 * ----------------------------------------------------------------------------
 *   # Full-universe, all timeframes, auto-windowed & redundant:
 *   php artisan polygon:fundamentals:ingest --timeframe=all
 *
 *   # Single ticker, annual only, exact manual date filters (no auto windowing):
 *   php artisan polygon:fundamentals:ingest AAPL --timeframe=annual --gte=2019-01-01
 *
 *   # Full-universe, but smaller windows & shorter redundancy:
 *   php artisan polygon:fundamentals:ingest --window=180 --redundancy-days=90
 *
 * ============================================================================
 */
class PolygonFundamentalsIngest extends Command
{
    /**
     * Command signature.
     */
    protected $signature = 'polygon:fundamentals:ingest
                            {ticker? : Optional specific ticker symbol}
                            {--limit= : Optional page size per API call (omit for provider default)}
                            {--order=desc : Sort order returned by API: asc|desc}
                            {--timeframe=all : Timeframe: annual|quarterly|ttm|all}
                            {--gte= : filing_date.gte (≥ start date, YYYY-MM-DD)}
                            {--gt= : filing_date.gt (> start date, YYYY-MM-DD)}
                            {--lte= : filing_date.lte (≤ end date, YYYY-MM-DD)}
                            {--lt= : filing_date.lt (< end date, YYYY-MM-DD)}
                            {--batch=200 : Approximate maximum jobs per Bus::batch}
                            {--sleep=5 : Seconds to pause between batch dispatches}
                            {--sync : Run jobs synchronously for debugging (bypass queue)}
                            {--window=365 : Window size in days when auto chunking date ranges (>=1)}
                            {--redundancy-days=365 : Days to backtrack from last filing_date when auto-ranging (>=0)}';

    /**
     * Description.
     */
    protected $description = 'Queue and batch ingest of Polygon Fundamentals for one or all tickers, with smart date windowing and redundancy.';

    /**
     * Entry point.
     */
    public function handle(): int
    {
        $tickerArg        = $this->argument('ticker');
        $limit            = $this->option('limit');
        $order            = $this->option('order') ?: 'desc';
        $timeframeInput   = $this->option('timeframe') ?: 'all';
        $batchSize        = (int) $this->option('batch');
        $sleep            = (int) $this->option('sleep');
        $syncMode         = (bool) $this->option('sync');
        $windowDays       = (int) ($this->option('window') ?? config('polygon.fundamentals_window_days', 365));
        $redundancyDays   = (int) ($this->option('redundancy-days') ?? config('polygon.fundamentals_redundancy_days', 365));

        // Ensure sane minimums
        if ($windowDays < 1) {
            $windowDays = 1;
        }
        if ($redundancyDays < 0) {
            $redundancyDays = 0;
        }

        // User-provided filing_date filters
        $gte = $this->option('gte');
        $gt  = $this->option('gt');
        $lte = $this->option('lte');
        $lt  = $this->option('lt');

        $hasUserDateFilters = $gte !== null || $gt !== null || $lte !== null || $lt !== null;

        // Determine which timeframes to process
        $timeframes = $this->resolveTimeframes($timeframeInput);

        // Base options forwarded into each job (augmented later per ticker/timeframe/window)
        $baseOptions = array_filter([
            'limit'             => $limit !== null ? (int) $limit : null,
            'order'             => $order,
            'filing_date.gte'   => $gte,
            'filing_date.gt'    => $gt,
            'filing_date.lte'   => $lte,
            'filing_date.lt'    => $lt,
        ], fn ($v) => $v !== null);

        Log::channel('ingest')->info('🚀 Starting fundamentals ingest command (v3.0)', [
            'ticker'              => $tickerArg,
            'timeframes'          => $timeframes,
            'base_options'        => $baseOptions,
            'mode'                => $syncMode ? 'sync' : 'queued',
            'window_days'         => $windowDays,
            'redundancy_days'     => $redundancyDays,
            'has_user_date_range' => $hasUserDateFilters,
        ]);

        if ($tickerArg) {
            return $this->handleSingleTicker(
                $tickerArg,
                $baseOptions,
                $timeframes,
                $hasUserDateFilters,
                $syncMode,
                $windowDays,
                $redundancyDays
            );
        }

        return $this->handleBatchIngestion(
            $baseOptions,
            $timeframes,
            $batchSize,
            $sleep,
            $syncMode,
            $windowDays,
            $redundancyDays,
            $hasUserDateFilters
        );
    }

    /**
     * Resolve timeframe input into a canonical list.
     */
    protected function resolveTimeframes(?string $timeframe): array
    {
        if ($timeframe === null || $timeframe === 'all') {
            return ['quarterly', 'annual', 'ttm'];
        }

        $allowed = ['quarterly', 'annual', 'ttm'];
        if (! in_array($timeframe, $allowed, true)) {
            $this->error("❌ Invalid timeframe: {$timeframe}. Allowed: " . implode(', ', $allowed) . ", or 'all'");
            exit(self::FAILURE);
        }

        return [$timeframe];
    }

    /**
     * Handle ingestion for a SINGLE ticker.
     */
    protected function handleSingleTicker(
        string $tickerSymbol,
        array $baseOptions,
        array $timeframes,
        bool $hasUserDateFilters,
        bool $syncMode,
        int $windowDays,
        int $redundancyDays
    ): int {
        $symbol = trim($tickerSymbol);

        $this->info("📘 Queuing fundamentals ingestion for {$symbol}...");
        Log::channel('ingest')->info("📘 Single-ticker fundamentals ingestion initializing", [
            'symbol'              => $symbol,
            'timeframes'          => $timeframes,
            'base_options'        => $baseOptions,
            'has_user_date_range' => $hasUserDateFilters,
        ]);

        $tickerModel = Ticker::where('ticker', $symbol)->first();

        if (! $tickerModel) {
            $this->error("❌ Ticker {$symbol} not found.");
            Log::channel('ingest')->warning('⚠️ Ticker not found for fundamentals ingestion', [
                'symbol' => $symbol,
            ]);

            return self::FAILURE;
        }

        try {
            $jobs = $this->buildJobsForTicker(
                $tickerModel,
                $timeframes,
                $baseOptions,
                $hasUserDateFilters,
                $windowDays,
                $redundancyDays
            );

            if (empty($jobs)) {
                $this->info("✅ No fundamentals work needed for {$symbol} (all timeframes up-to-date).");
                Log::channel('ingest')->info('ℹ️ No fundamentals jobs generated (single ticker)', [
                    'symbol'     => $symbol,
                    'timeframes' => $timeframes,
                ]);

                return self::SUCCESS;
            }

            if ($syncMode) {
                Log::channel('ingest')->info('⚙️ Running single-ticker fundamentals in SYNC mode', [
                    'symbol'        => $symbol,
                    'job_count'     => count($jobs),
                    'timeframes'    => $timeframes,
                ]);

                $service = app(\App\Services\PolygonFundamentalsService::class);

                foreach ($jobs as $job) {
                    /** @var IngestTickerFundamentalsJob $job */
                    $job->handle($service);
                }

                $this->info("✅ Completed fundamentals ingestion for {$symbol} in SYNC mode.");
                return self::SUCCESS;
            }

            // Queue mode: single Bus batch (no then()/catch() closures to avoid closure serialization issues)
            $batch = Bus::batch($jobs)
                ->name("PolygonFundamentals Single ({$symbol})")
                ->onConnection('database')
                ->onQueue('default')
                ->dispatch();

            Log::channel('ingest')->info('✅ Single-ticker fundamentals batch dispatched', [
                'symbol'    => $symbol,
                'job_count' => $batch->totalJobs,
                'batch_id'  => $batch->id ?? null,
            ]);

            $this->info("✅ Dispatched fundamentals batch for {$symbol} ({$batch->totalJobs} jobs).");

        } catch (Throwable $e) {
            Log::channel('ingest')->error('❌ Failed dispatching single-ticker fundamentals job(s)', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);

            $this->error("❌ Dispatch failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Handle BATCH ingestion for the active universe.
     */
    protected function handleBatchIngestion(
        array $baseOptions,
        array $timeframes,
        int $batchSize,
        int $sleep,
        bool $syncMode,
        int $windowDays,
        int $redundancyDays,
        bool $hasUserDateFilters
    ): int {
        $this->info('🔎 Selecting active tickers for fundamentals ingestion...');
        Log::channel('ingest')->info('🔎 Selecting active tickers for fundamentals batch ingestion');

        $tickerQuery = Ticker::select('id', 'ticker')
            ->where('active', true)
            ->orderBy('id');

        $totalTickers = $tickerQuery->count();

        if ($totalTickers === 0) {
            $this->warn('⚠️ No active tickers found for fundamentals ingestion.');
            Log::channel('ingest')->warning('⚠️ No active tickers found (fundamentals)');
            return self::SUCCESS;
        }

        $this->info("🧱 Dispatching fundamentals ingestion for {$totalTickers} tickers across " . implode(', ', $timeframes));
        Log::channel('ingest')->info('🧱 Fundamentals batch ingestion starting', [
            'total_tickers'       => $totalTickers,
            'timeframes'          => $timeframes,
            'batch_size_jobs'     => $batchSize,
            'sleep_seconds'       => $sleep,
            'mode'                => $syncMode ? 'sync' : 'queued',
            'window_days'         => $windowDays,
            'redundancy_days'     => $redundancyDays,
            'has_user_date_range' => $hasUserDateFilters,
        ]);

        $tickerBar = $this->output->createProgressBar($totalTickers);
        $tickerBar->setFormat("   🟢 Tickers: %current%/%max% [%bar%] %percent:3s%%");
        $tickerBar->start();

        $batchNumber          = 0;
        $totalJobsDispatched  = 0;
        $jobsBuffer           = [];

        $tickerQuery->chunkById(200, function ($tickers) use (
            $timeframes,
            $baseOptions,
            $hasUserDateFilters,
            $windowDays,
            $redundancyDays,
            $batchSize,
            $sleep,
            $syncMode,
            $tickerBar,
            &$batchNumber,
            &$totalJobsDispatched,
            &$jobsBuffer
        ) {
            foreach ($tickers as $ticker) {
                /** @var Ticker $ticker */

                $jobsForTicker = $this->buildJobsForTicker(
                    $ticker,
                    $timeframes,
                    $baseOptions,
                    $hasUserDateFilters,
                    $windowDays,
                    $redundancyDays
                );

                if (empty($jobsForTicker)) {
                    Log::channel('ingest')->info('⏭ Fundamentals up-to-date for ticker (no jobs generated)', [
                        'ticker'     => $ticker->ticker,
                        'timeframes' => $timeframes,
                    ]);
                    $tickerBar->advance();
                    continue;
                }

                foreach ($jobsForTicker as $job) {
                    $jobsBuffer[] = $job;
                    $totalJobsDispatched++;

                    if (count($jobsBuffer) >= $batchSize) {
                        $batchNumber++;
                        $this->dispatchJobBatch($jobsBuffer, $batchNumber, $sleep, $syncMode);
                        $jobsBuffer = [];
                    }
                }

                $tickerBar->advance();
            }
        });

        // Flush any remaining jobs
        if (! empty($jobsBuffer)) {
            $batchNumber++;
            $this->dispatchJobBatch($jobsBuffer, $batchNumber, $sleep, $syncMode);
        }

        $tickerBar->finish();
        $this->newLine(2);

        if ($totalJobsDispatched === 0) {
            $this->info('✅ Fundamentals ingestion: no new work detected for any ticker.');
            Log::channel('ingest')->info('✅ Fundamentals ingestion complete — nothing new to ingest.');
        } else {
            $this->info("✅ Queued {$totalJobsDispatched} fundamentals jobs across {$batchNumber} batch(es).");
            Log::channel('ingest')->info('✅ Finished queuing fundamentals batches', [
                'batches'         => $batchNumber,
                'total_jobs'      => $totalJobsDispatched,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Build fundamentals jobs for a single ticker across all requested timeframes.
     *
     * Invariants:
     *   • If user supplied any filing_date filters → one job per timeframe (no windowing).
     *   • If no user date filters:
     *        - we do redundancy + windowing using filing_date.gte / lte.
     */
    protected function buildJobsForTicker(
        Ticker $ticker,
        array $timeframes,
        array $baseOptions,
        bool $hasUserDateFilters,
        int $windowDays,
        int $redundancyDays
    ): array {
        $jobs    = [];
        $today   = Carbon::today();
        $minDate = Carbon::parse(config('polygon.fundamentals_min_date', '2015-01-01'))->startOfDay();

        foreach ($timeframes as $tf) {
            if ($hasUserDateFilters) {
                // Honor explicit user date filters EXACTLY — no auto magic.
                $opts = $baseOptions;
                $opts['timeframe'] = $tf;

                Log::channel('ingest')->info('📡 Queueing fundamentals job (explicit date filters)', [
                    'ticker'     => $ticker->ticker,
                    'ticker_id'  => $ticker->id,
                    'timeframe'  => $tf,
                    'options'    => $opts,
                ]);

                $jobs[] = new IngestTickerFundamentalsJob($ticker->id, $opts);
                continue;
            }

            // -----------------------------------------------------------------
            // Auto-range mode (no user date filters):
            //   1) Find latest filing_date for (ticker, timeframe).
            //   2) Backtrack redundancyDays from that date.
            //   3) Clamp to minDate.
            //   4) Window [start, today] into N slices of windowDays.
            // -----------------------------------------------------------------
            $latestFilingDate = DB::table('ticker_fundamentals')
                ->where('ticker_id', $ticker->id)
                ->where('timeframe', $tf)
                ->max('filing_date');

            if ($latestFilingDate) {
                $startDate = Carbon::parse($latestFilingDate)
                    ->subDays($redundancyDays)
                    ->startOfDay();
            } else {
                $startDate = $minDate->copy();
            }

            // Clamp to minDate safety floor
            if ($startDate->lessThan($minDate)) {
                $startDate = $minDate->copy();
            }

            $endDate = $today->copy();

            // If the entire redundancy window is already in the "future" relative to today → nothing to do.
            if ($startDate->greaterThan($endDate)) {
                Log::channel('ingest')->info('⏭ Skipping fundamentals (up-to-date with redundancy)', [
                    'ticker'         => $ticker->ticker,
                    'ticker_id'      => $ticker->id,
                    'timeframe'      => $tf,
                    'latest_filing'  => $latestFilingDate,
                    'start_date'     => $startDate->toDateString(),
                    'end_date'       => $endDate->toDateString(),
                    'redundancyDays' => $redundancyDays,
                ]);
                continue;
            }

            $cursorStart = $startDate->copy();

            while ($cursorStart->lessThanOrEqualTo($endDate)) {
                $cursorEnd = $cursorStart->copy()->addDays($windowDays - 1);

                if ($cursorEnd->greaterThan($endDate)) {
                    $cursorEnd = $endDate->copy();
                }

                $fromStr = $cursorStart->toDateString();
                $toStr   = $cursorEnd->toDateString();

                $opts = $baseOptions;
                // NOTE: we override any existing filing_date.* in baseOptions in auto-mode.
                $opts['timeframe']        = $tf;
                $opts['filing_date.gte']  = $fromStr;
                $opts['filing_date.lte']  = $toStr;

                Log::channel('ingest')->info('📡 Queueing fundamentals subjob (windowed)', [
                    'ticker'         => $ticker->ticker,
                    'ticker_id'      => $ticker->id,
                    'timeframe'      => $tf,
                    'from'           => $fromStr,
                    'to'             => $toStr,
                    'window_days'    => $windowDays,
                    'redundancyDays' => $redundancyDays,
                ]);

                $jobs[] = new IngestTickerFundamentalsJob($ticker->id, $opts);

                $cursorStart = $cursorEnd->copy()->addDay();

                // Safety guard in case of any strange date math
                if ($cursorStart->diffInDays($startDate, false) > 3650) {
                    Log::channel('ingest')->warning('⚠️ Aborting fundamentals window loop due to excessive span', [
                        'ticker'    => $ticker->ticker,
                        'timeframe' => $tf,
                        'start'     => $startDate->toDateString(),
                        'end'       => $endDate->toDateString(),
                    ]);
                    break;
                }
            }
        }

        return $jobs;
    }

    /**
     * Dispatch a batch of jobs either synchronously or via Bus::batch.
     */
    protected function dispatchJobBatch(array $jobs, int $batchNumber, int $sleep, bool $syncMode): void
    {
        $jobCount = count($jobs);
        $logger   = Log::channel('ingest');

        if ($jobCount === 0) {
            return;
        }

        try {
            if ($syncMode) {
                $logger->info("⚙️ Running fundamentals batch #{$batchNumber} in SYNC mode", [
                    'job_count' => $jobCount,
                ]);

                $service = app(\App\Services\PolygonFundamentalsService::class);

                foreach ($jobs as $job) {
                    /** @var IngestTickerFundamentalsJob $job */
                    $job->handle($service);
                }

                return;
            }

            $logger->info("📦 Dispatching fundamentals batch #{$batchNumber}", [
                'job_count' => $jobCount,
            ]);

            // Ensure DB connection is alive before heavy batch dispatch
            DB::connection()->reconnect();

            $batch = Bus::batch($jobs)
                ->name("PolygonFundamentals Batch #{$batchNumber}")
                ->onConnection('database')
                ->onQueue('default')
                ->dispatch();

            $this->info("✅ Dispatched fundamentals batch #{$batchNumber} ({$batch->totalJobs} jobs)");

            if ($sleep > 0) {
                $logger->info("😴 Sleeping for {$sleep}s before next fundamentals batch", [
                    'batch_number' => $batchNumber,
                ]);
                sleep($sleep);
            }
        } catch (Throwable $e) {
            $logger->error('❌ Error dispatching fundamentals batch', [
                'batch_number' => $batchNumber,
                'error'        => $e->getMessage(),
            ]);

            $this->error("❌ Failed to dispatch fundamentals batch #{$batchNumber}: {$e->getMessage()}");
        }
    }
}