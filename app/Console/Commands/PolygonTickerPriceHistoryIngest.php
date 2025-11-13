<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\IngestTickerPriceHistoryJob;
use App\Services\BatchMonitorService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ============================================================================
 *  polygon:ticker-price-histories:ingest  
 *  (v3.0 — Smart Missing-Date Queue Ingest + Symbol-Aware Case Handling)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Dispatch asynchronous (queued) Polygon.io price-history ingestion jobs
 *   for all tickers or a specific ticker via --symbol.  
 *   NOW includes automatic “only ingest missing dates per ticker”.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Fetches all tickers (or one via --symbol).
 *   • Determines per-ticker start date automatically IF --from is omitted:
 *         → latest stored 't' for given resolution + 1 day
 *   • Falls back to polygon.price_history_min_date if no historical price exists.
 *   • If user passes --from manually, uses that globally exactly as before.
 *   • Skips tickers whose latest stored date already extends past --to.
 *   • Dispatches queue jobs in batches with optional throttling.
 *   • Integrates with BatchMonitorService for tracking.
 *
 * ⚙️ Config Integration (config/polygon.php):
 * ----------------------------------------------------------------------------
 *     price_history_min_date
 *     default_timespan
 *     default_multiplier
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
 * 🚀 New in v3.0:
 * ----------------------------------------------------------------------------
 *   • Automatic per-ticker missing-date detection (HUGE performance savings)
 *   • Case-sensitive symbol handling retained
 *   • Massive reduction of unnecessary Polygon API calls
 *
 * ============================================================================
 */
class PolygonTickerPriceHistoryIngest extends Command
{
    protected $signature = 'polygon:ticker-price-histories:ingest
                            {--symbol= : Optional single ticker symbol to ingest (case-sensitive)}
                            {--resolution=1d : Resolution (1d, 1m, 5m, etc.)}
                            {--from= : Global start date. If omitted, missing-date logic applies.}
                            {--to= : End date (YYYY-MM-DD), defaults to today}
                            {--limit=0 : Limit total tickers processed (0 = all)}
                            {--batch=500 : Number of tickers per job batch}
                            {--sleep=5 : Seconds to sleep between batches}';

    protected $description = 'Queue Polygon.io price-history ingestion jobs for all or specific tickers with smart missing-date detection.';

    public function handle(): int
    {
        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Load Options & Config Defaults
        |--------------------------------------------------------------------------
        */
        $symbol = trim($this->option('symbol') ?? '');     // Case-sensitive — DO NOT uppercase.
        $resolution = $this->option('resolution') ?? config('polygon.default_timespan', '1d');

        $fromOption = $this->option('from');
        $from       = $fromOption ?? config('polygon.price_history_min_date', '2020-01-01');
        $fromWasExplicit = $fromOption !== null;

        $toOption = $this->option('to');
        $to       = $toOption ?: now()->toDateString();

        $limit     = (int) $this->option('limit', 0);
        $batchSize = (int) $this->option('batch', 500);
        $sleep     = (int) $this->option('sleep', 5);

        $logger = Log::channel('ingest');

        $logger->info('📥 Polygon price-history ingest started', [
            'symbol'     => $symbol ?: 'ALL',
            'resolution' => $resolution,
            'from'       => $from,
            'to'         => $to,
            'limit'      => $limit,
            'batchSize'  => $batchSize,
            'sleep'      => $sleep,
            'autoFrom'   => !$fromWasExplicit,
        ]);

        $this->info("📈 Preparing Polygon ticker price ingestion...");
        $this->line("   Symbol     : " . ($symbol ?: 'ALL TICKERS'));
        $this->line("   Resolution : {$resolution}");
        $this->line("   Global From: {$from}" . ($fromWasExplicit ? " (explicit)" : " (auto-mode)"));
        $this->line("   To         : {$to}");
        $this->line("   Batch Size : {$batchSize}");
        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Build Ticker Query (case-sensitive)
        |--------------------------------------------------------------------------
        */
        $query = Ticker::orderBy('id')->select('id', 'ticker');

        if ($symbol) {
            $query->where('ticker', $symbol);
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
        | 3️⃣ Initialize Batch Monitor + Progress Bar
        |--------------------------------------------------------------------------
        */
        BatchMonitorService::createBatch('PolygonTickerPriceHistories', $total);

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat("   🟢 Progress: %current%/%max% [%bar%] %percent:3s%%");
        $bar->start();

        $batchIndex = 1;

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Chunk & Dispatch Jobs (per-ticker missing-date logic)
        |--------------------------------------------------------------------------
        */
        $query->chunk($batchSize, function ($tickers) use (
            $resolution, $from, $to, $sleep, $bar, $logger, $fromWasExplicit, &$batchIndex
        ) {
            $jobs = [];

            foreach ($tickers as $ticker) {

                /*
                 * 🔎 Missing-Date Logic:
                 * ----------------------
                 * If user passed --from manually → use it globally.
                 * Otherwise → auto-detect last stored date, then effectiveFrom = (lastDate + 1 day).
                 */
                if ($fromWasExplicit) {
                    $effectiveFrom = $from;
                } else {
                    $latestT = $ticker->priceHistories()
                        ->where('resolution', $resolution)
                        ->max('t');

                    if ($latestT) {
                        $effectiveFrom = Carbon::parse($latestT)->addDay()->toDateString();
                    } else {
                        $effectiveFrom = $from; // fallback global min date
                    }
                }

                // 🛑 Already fully up-to-date? Skip job dispatching.
                if ($effectiveFrom > $to) {
                    $logger->info('⏭ Skipping up-to-date ticker', [
                        'ticker' => $ticker->ticker,
                        'resolution' => $resolution,
                        'nextFrom' => $effectiveFrom,
                        'to' => $to,
                    ]);
                    $bar->advance();
                    continue;
                }

                $logger->info('📡 Queueing price history ingest job', [
                    'ticker' => $ticker->ticker,
                    'resolution' => $resolution,
                    'from' => $effectiveFrom,
                    'to' => $to,
                ]);

                $jobs[] = new IngestTickerPriceHistoryJob(
                    $ticker,
                    $effectiveFrom,
                    $to,
                    $resolution
                );

                $bar->advance();
            }

            if (empty($jobs)) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Dispatch this chunk as a batch
            |--------------------------------------------------------------------------
            */
            try {
                $batch = Bus::batch($jobs)
                    ->name("PolygonTickerPriceHistoriesIngest (Batch #{$batchIndex})")
                    ->onConnection('database')
                    ->onQueue('default')
                    ->dispatch();

                $logger->info('✅ Dispatched ingestion batch', [
                    'batch_index' => $batchIndex,
                    'batch_id'    => $batch->id ?? null,
                    'job_count'   => count($jobs),
                ]);

                $this->newLine();
                $this->info("✅ Dispatched batch #{$batchIndex} (" . count($jobs) . " jobs)");

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
        $logger->info('🏁 Polygon price-history ingestion complete', [
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
        if (!Schema::hasColumn('job_batches', 'processed_jobs')) {
            $this->warn("⚠️ 'job_batches' table appears outdated. Run:");
            $this->line("   php artisan queue:batches-table && php artisan migrate");
            $this->line("   This adds 'processed_jobs' for accurate queue metrics.");
            $this->newLine();
        }

        return Command::SUCCESS;
    }
}