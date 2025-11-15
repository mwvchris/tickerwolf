<?php

namespace App\Console\Commands;

use App\Jobs\IngestTickerPriceHistoryJob;
use App\Models\Ticker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 *  polygon:ticker-price-histories:ingest
 *  v3.0.0 — Multi-Ticker, Multi-Resolution (1d + 1h) Aggressive Batching
 * ============================================================================
 *
 * 🔧 Purpose
 * ----------------------------------------------------------------------------
 * Dispatches queue jobs to ingest Polygon.io price history for the current
 * ticker universe. This version is optimized for SPEED and LOW JOB COUNT:
 *
 *   • Each queue job processes MANY tickers (default: 100 per job, but we
 *     actually inherit your old --batch value, e.g. 500, for max throughput).
 *   • Each job fetches BOTH 1d and 1h bars for its ticker group in a single
 *     execution (the job-level logic lives in IngestTickerPriceHistoryJob).
 *   • Per-ticker auto-from logic is handled inside the job, using the DB to
 *     find the latest existing bar and backfilling with a small redundancy.
 *
 * 🧠 High-Level Behavior
 * ----------------------------------------------------------------------------
 *  1. Select active Polygon tickers (or a single symbol).
 *  2. Stream them via cursor() so we never load all into memory at once.
 *  3. Group tickers into multi-ticker jobs (size = --batch).
 *  4. Each job:
 *      • Fetches 1d and 1h bars (auto-from aware).
 *      • Upserts bars into ticker_price_histories in bulk.
 *  5. All jobs are dispatched as a single Bus batch on the "database" queue.
 *
 * ⚡ Aggressive Defaults (Preset A)
 * ----------------------------------------------------------------------------
 *  • Jobs are multi-ticker (100+ tickers per job is normal).
 *  • 1d auto-from lookback: ~45 days (handled in the job).
 *  • 1h auto-from lookback: ~7 days (handled in the job).
 *  • 1h retention purge: 168 hours (handled in the job).
 *
 * These are aggressively tuned for your Docker setup, but everything is still
 * overrideable via flags if needed.
 *
 * 📦 Typical Usage
 * ----------------------------------------------------------------------------
 *  # Nightly full-universe ingest (1d + 1h)
 *  php artisan polygon:ticker-price-histories:ingest
 *
 *  # Limit to first 500 tickers
 *  php artisan polygon:ticker-price-histories:ingest --limit=500
 *
 *  # Single symbol ad-hoc ingest
 *  php artisan polygon:ticker-price-histories:ingest AAPL
 *
 *  # tickers:refresh-all will continue to call this, passing --batch=500, etc.
 *
 * 🧩 Related
 * ----------------------------------------------------------------------------
 *  • App\Jobs\IngestTickerPriceHistoryJob         (multi-ticker, 1d + 1h)
 *  • App\Services\PolygonTickerPriceHistoryService (low-level API + upserts)
 *  • tickers:refresh-all umbrella command
 *  • docker "tickerwolf-queue" worker
 * ============================================================================
 */
class PolygonTickerPriceHistoryIngest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * NOTE:
     *  - We keep the old options (symbol, resolution, limit, batch, sleep, etc.)
     *    for backwards compatibility with tickers:refresh-all.
     *  - Internally we now ALWAYS treat this as a multi-resolution ingest:
     *    IngestTickerPriceHistoryJob will handle both 1d and 1h.
     */
    protected $signature = 'polygon:ticker-price-histories:ingest
                            {symbol=ALL : Ticker symbol (e.g. AAPL) or ALL for full universe}
                            {--resolution=both : 1d, 1h, or both (jobs treat "both" as default)}
                            {--limit=0 : Limit total tickers processed (0 = all)}
                            {--batch=100 : Tickers per queue job (multi-ticker job size)}
                            {--from=2020-01-01 : Global baseline from-date (auto-from aware)}
                            {--to= : Global to-date (default: today)}
                            {--window-days=45 : Daily auto-from lookback window (days)}
                            {--redundancy-days=2 : Redundant daily overlap (days)}
                            {--redundancy-hours=3 : Redundant intraday overlap (hours)}
                            {--retention-hours=168 : Hourly retention window for purge}
                            {--sleep=0 : Seconds to sleep between Bus batch dispatch}
                            {--fast : Apply aggressive preset A overrides}
                            {--dev : Dev mode (auto-limit universe for testing)}';

    /**
     * The console command description.
     */
    protected $description = 'Ingest Polygon.io ticker price histories (1d + 1h) in aggressive multi-ticker batches.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        // ---------------------------------------------------------------------
        // 1️⃣ Resolve options & presets
        // ---------------------------------------------------------------------
        $symbol         = strtoupper($this->argument('symbol') ?? 'ALL');
        $resolutionMode = strtolower($this->option('resolution') ?? 'both'); // "1d", "1h", or "both"
        $limit          = (int) $this->option('limit');
        $tickersPerJob  = max(1, (int) $this->option('batch'));
        $sleep          = max(0, (int) $this->option('sleep'));

        $globalFrom     = $this->option('from') ?: '2020-01-01';
        $toDate         = $this->option('to') ?: Carbon::today()->toDateString();

        $windowDays     = max(1, (int) $this->option('window-days'));
        $redundancyDays = max(0, (int) $this->option('redundancy-days'));
        $redundancyHours = max(0, (int) $this->option('redundancy-hours'));
        $retentionHours = max(0, (int) $this->option('retention-hours'));

        $fastMode       = (bool) $this->option('fast');
        $devMode        = (bool) $this->option('dev');

        // Apply aggressive preset A (can be tweaked here if needed)
        if ($fastMode) {
            // If someone passes --fast, we bump a few knobs even harder.
            $tickersPerJob  = max($tickersPerJob, 150);  // at least 150 per job
            $windowDays     = max($windowDays, 45);
            $redundancyDays = max($redundancyDays, 2);
            $redundancyHours = max($redundancyHours, 3);
        }

        // Dev mode: auto-limit universe if no explicit limit provided
        if ($devMode && $limit === 0) {
            $limit = 250;
        }

        $logger = Log::channel('ingest');

        $logger->info('📥 Polygon price-history ingest started', [
            'symbol'          => $symbol,
            'resolution'      => $resolutionMode,
            'global_from'     => $globalFrom,
            'to'              => $toDate,
            'limit'           => $limit,
            'tickersPerJob'   => $tickersPerJob,
            'sleep'           => $sleep,
            'window_days'     => $windowDays,
            'redundancyDays'  => $redundancyDays,
            'redundancyHours' => $redundancyHours,
            'retentionHours'  => $retentionHours,
            'autoFromMode'    => true,
            'fastMode'        => $fastMode,
            'devMode'         => $devMode,
        ]);

        $this->info('📈 Preparing Polygon ticker price ingestion...');
        $this->line('   Symbol         : ' . ($symbol === 'ALL' ? 'ALL TICKERS' : $symbol));
        $this->line('   Resolution     : ' . $resolutionMode . ' (jobs will treat "both" as 1d + 1h)');
        $this->line('   Global From    : ' . $globalFrom . ' (auto-from baseline)');
        $this->line('   To             : ' . $toDate);
        $this->line('   Tickers/Job    : ' . $tickersPerJob . ' (multi-ticker jobs)');
        $this->line('   Window Size    : ' . $windowDays . ' day(s) (daily auto-from)');
        $this->line('   Redundancy     : ' . $redundancyDays . ' day(s), ' . $redundancyHours . ' hour(s)');
        $this->line('   1h Retention   : ' . $retentionHours . ' hour(s)');
        $this->line('   Mode           : ' . ($fastMode ? 'FAST/Aggressive' : 'Normal') . ($devMode ? ' + DEV' : ''));
        $this->newLine();

        // ---------------------------------------------------------------------
        // 2️⃣ Build ticker universe query
        // ---------------------------------------------------------------------
        $tickerQuery = Ticker::query()
            ->where('is_active_polygon', true)
            ->orderBy('id')
            ->select(['id', 'ticker', 'type']);

        if ($symbol !== 'ALL') {
            $tickerQuery->whereRaw('UPPER(ticker) = ?', [$symbol]);
        }

        if ($limit > 0) {
            $tickerQuery->limit($limit);
        }

        $total = (clone $tickerQuery)->count();

        if ($total === 0) {
            $this->warn('⚠️ No tickers found for price history ingestion.');
            $logger->warning('⚠️ No tickers found for price history ingestion.', [
                'symbol' => $symbol,
                'limit'  => $limit,
            ]);

            return Command::SUCCESS;
        }

        $this->info("🔢 Found {$total} ticker(s) to process.");
        $this->newLine();

        // ---------------------------------------------------------------------
        // 3️⃣ Stream tickers & assemble multi-ticker jobs
        // ---------------------------------------------------------------------
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat('   🟢 Tickers: %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        $jobs              = [];
        $tickerBuffer      = [];
        $dispatchedTickers = 0;

        foreach ($tickerQuery->cursor() as $ticker) {
            $tickerBuffer[] = [
                'id'     => $ticker->id,
                'ticker' => $ticker->ticker,
                'type'   => $ticker->type,
            ];

            // When the buffer reaches the target size, turn it into one job.
            if (count($tickerBuffer) >= $tickersPerJob) {
                $jobs[] = $this->makeJobFromBuffer(
                    $tickerBuffer,
                    $resolutionMode,
                    $globalFrom,
                    $toDate,
                    $windowDays,
                    $redundancyDays,
                    $redundancyHours,
                    $retentionHours
                );

                $dispatchedTickers += count($tickerBuffer);
                $bar->advance(count($tickerBuffer));

                $tickerBuffer = [];
            }
        }

        // Flush remaining tickers into a final job
        if (!empty($tickerBuffer)) {
            $jobs[] = $this->makeJobFromBuffer(
                $tickerBuffer,
                $resolutionMode,
                $globalFrom,
                $toDate,
                $windowDays,
                $redundancyDays,
                $redundancyHours,
                $retentionHours
            );

            $dispatchedTickers += count($tickerBuffer);
            $bar->advance(count($tickerBuffer));

            $tickerBuffer = [];
        }

        $bar->finish();
        $this->newLine(2);

        if (empty($jobs)) {
            $this->warn('⚠️ No jobs were created (unexpected).');
            $logger->warning('⚠️ No jobs created in PolygonTickerPriceHistoryIngest.', [
                'total_tickers' => $total,
            ]);

            return Command::SUCCESS;
        }

        // ---------------------------------------------------------------------
        // 4️⃣ Dispatch as a single Bus batch (for monitoring)
        // ---------------------------------------------------------------------
        try {
            $batch = Bus::batch($jobs)
                ->name('PolygonTickerPriceHistoryIngest (multi-ticker 1d+1h)')
                ->onConnection('database')
                ->onQueue('default')
                ->dispatch();

            $logger->info('✅ Dispatched Polygon price-history Bus batch', [
                'batch_id'          => $batch->id ?? null,
                'job_count'         => count($jobs),
                'total_tickers'     => $total,
                'dispatched_tickers'=> $dispatchedTickers,
                'tickers_per_job'   => $tickersPerJob,
                'resolution_mode'   => $resolutionMode,
            ]);

            $this->info('✅ Dispatched Polygon price-history Bus batch:');
            $this->line('   Jobs dispatched : ' . count($jobs));
            $this->line('   Tickers targeted : ' . $total);
            $this->line('   Tickers buffered : ' . $dispatchedTickers);
            $this->line('   Batch ID         : ' . ($batch->id ?? 'n/a'));
            $this->newLine();
        } catch (Throwable $e) {
            $logger->error('❌ Failed to dispatch Polygon price-history Bus batch', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);

            $this->error('❌ Failed to dispatch Polygon price-history Bus batch: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info('🎯 All multi-ticker price-history jobs dispatched (1d + 1h).');
        return Command::SUCCESS;
    }

    /**
     * Build a single multi-ticker price-history job from a buffer of tickers.
     *
     * @param  array<int, array{id:int,ticker:string,type:?string}>  $buffer
     * @param  string  $resolutionMode  "1d", "1h", or "both"
     * @param  string  $globalFrom
     * @param  string  $toDate
     * @param  int     $windowDays
     * @param  int     $redundancyDays
     * @param  int     $redundancyHours
     * @param  int     $retentionHours
     * @return \App\Jobs\IngestTickerPriceHistoryJob
     */
    protected function makeJobFromBuffer(
        array $buffer,
        string $resolutionMode,
        string $globalFrom,
        string $toDate,
        int $windowDays,
        int $redundancyDays,
        int $redundancyHours,
        int $retentionHours
    ): IngestTickerPriceHistoryJob {
        // NOTE: The job will:
        //  - Loop these tickers
        //  - Compute per-ticker auto-from for 1d and 1h
        //  - Fetch data via PolygonTickerPriceHistoryService
        //  - Upsert into ticker_price_histories in bulk
        return new IngestTickerPriceHistoryJob(
            $buffer,
            $resolutionMode,
            $globalFrom,
            $toDate,
            $windowDays,
            $redundancyDays,
            $redundancyHours,
            $retentionHours
        );
    }
}