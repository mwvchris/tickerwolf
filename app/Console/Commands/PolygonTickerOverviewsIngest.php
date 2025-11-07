<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Ticker;
use App\Jobs\IngestTickerOverviewJob;
use Throwable;

/**
 * =============================================================================
 *  COMMAND: polygon:ticker-overviews:ingest
 * =============================================================================
 *
 *  PURPOSE:
 *  --------
 *  This Artisan command orchestrates the ingestion of **Polygon.io ticker
 *  overviews** for all tickers currently present in the `tickers` table.
 *
 *  It does so by:
 *    1. Retrieving all tickers from the database.
 *    2. Chunking them into batches (configurable via `--batch` option).
 *    3. Creating a Laravel Bus **Batch** composed of multiple
 *       `IngestTickerOverviewJob` instances.
 *    4. Dispatching that batch to the queue system (`QUEUE_CONNECTION` = database).
 *
 *  Each job in the batch calls the PolygonTickerOverviewService to fetch and
 *  upsert overview data into the `ticker_overviews` table.
 *
 *  This command integrates with the `PolygonTickerOverviewsSchedule` class,
 *  which runs this nightly as part of the automated ingestion pipeline.
 *
 *  OPTIONS:
 *  --------
 *    --batch : Number of tickers to include per job (default: 500)
 *    --sleep : Seconds to pause between batch dispatches (default: 5)
 *
 *  LOGGING:
 *  --------
 *  All output is logged to the **"ingest"** logging channel (see
 *  `config/logging.php`) under `storage/logs/ingest.log`.
 *
 *  EXAMPLE USAGE:
 *  ---------------
 *  php artisan polygon:ticker-overviews:ingest --batch=1000 --sleep=10
 *
 *  ENVIRONMENT REQUIREMENTS:
 *  -------------------------
 *  • Polygon API credentials defined in `.env`
 *  • Database queues enabled (QUEUE_CONNECTION=database)
 *  • Proper indexing on `tickers.id` and `tickers.ticker`
 * =============================================================================
 */
class PolygonTickerOverviewsIngest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'polygon:ticker-overviews:ingest
                            {--batch=500 : Number of tickers per job batch}
                            {--sleep=5 : Seconds to sleep between batch dispatches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingest Polygon.io ticker overviews in batches using queued jobs.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        // ---------------------------------------------------------------------
        // STEP 1: Retrieve configuration options
        // ---------------------------------------------------------------------
        $batchSize = (int) $this->option('batch');
        $sleep = (int) $this->option('sleep');

        $this->info("Preparing to dispatch Polygon.io overview ingestion jobs...");
        Log::channel('ingest')->info("Starting Polygon overview ingestion command", [
            'batch_size'    => $batchSize,
            'sleep_seconds' => $sleep,
        ]);

        // ---------------------------------------------------------------------
        // STEP 2: Determine total tickers to process
        // ---------------------------------------------------------------------
        $totalTickers = Ticker::count();
        if ($totalTickers === 0) {
            $this->warn("No tickers found in the database. Nothing to ingest.");
            Log::channel('ingest')->warning("No tickers found in database — skipping overview ingestion.");
            return Command::SUCCESS;
        }

        $this->info("Found {$totalTickers} tickers.");
        $this->info("Chunking into batches of {$batchSize} tickers each...");

        Log::channel('ingest')->info("Ticker enumeration started", [
            'total_tickers' => $totalTickers,
            'batch_size'    => $batchSize,
        ]);

        // ---------------------------------------------------------------------
        // STEP 3: Build an array of IngestTickerOverviewJob instances
        // ---------------------------------------------------------------------
        $jobs = [];
        $chunk = [];
        $processed = 0;

        // Use a cursor to efficiently stream all tickers without memory exhaustion.
        $tickers = Ticker::select('id', 'ticker')->orderBy('id')->cursor();

        foreach ($tickers as $ticker) {
            $chunk[] = $ticker->ticker;
            $processed++;

            if (count($chunk) >= $batchSize) {
                $jobs[] = new IngestTickerOverviewJob($chunk);
                $chunk = [];
            }
        }

        // Handle any remaining tickers that didn’t fill the last chunk.
        if (!empty($chunk)) {
            $jobs[] = new IngestTickerOverviewJob($chunk);
        }

        if (empty($jobs)) {
            $this->warn("No jobs to dispatch (no tickers in queue).");
            Log::channel('ingest')->warning("No ticker overview ingestion jobs created.");
            return Command::SUCCESS;
        }

        // ---------------------------------------------------------------------
        // STEP 4: Dispatch the batch to Laravel's queue system
        // ---------------------------------------------------------------------
        $this->info("Dispatching " . count($jobs) . " overview ingestion jobs...");
        Log::channel('ingest')->info("Dispatching Polygon overview ingestion batch", [
            'total_jobs' => count($jobs),
        ]);

        $batch = Bus::batch($jobs)
            ->name('PolygonTickerOverviews')
            ->then(function (Batch $batch) {
                // Triggered when *all* jobs succeed.
                Log::channel('ingest')->info("✅ Polygon overview ingestion batch completed successfully", [
                    'batch_id'   => $batch->id,
                    'total_jobs' => $batch->totalJobs,
                ]);
            })
            ->catch(function (Batch $batch, Throwable $e) {
                // Triggered when *any* job in the batch fails.
                Log::channel('ingest')->error("❌ Polygon overview ingestion batch failed", [
                    'batch_id' => $batch->id,
                    'error'    => $e->getMessage(),
                    'trace'    => $e->getTraceAsString(),
                ]);
            })
            ->finally(function (Batch $batch) {
                // Always triggered at the end (success or failure).
                Log::channel('ingest')->info("🏁 Polygon overview ingestion batch finished", [
                    'batch_id'       => $batch->id,
                    'finished_jobs'  => $batch->processedJobs(),
                    'failed_jobs'    => $batch->failedJobs,
                    'pending_jobs'   => $batch->pendingJobs,
                ]);
            })
            ->dispatch();

        // ---------------------------------------------------------------------
        // STEP 5: Report batch dispatch success to console and logs
        // ---------------------------------------------------------------------
        $this->info("✅ Dispatched Polygon.io overview ingestion batch!");
        $this->line("   Batch ID: {$batch->id}");
        $this->line("   Total Jobs: {$batch->totalJobs}");

        Log::channel('ingest')->info("Polygon overview ingestion batch dispatched successfully", [
            'batch_id'   => $batch->id,
            'total_jobs' => $batch->totalJobs,
        ]);

        // Optional rate-limiting delay between batch dispatches (to prevent overload)
        if ($sleep > 0) {
            Log::channel('ingest')->debug("Sleeping between batch dispatches", [
                'seconds' => $sleep,
            ]);
            sleep($sleep);
        }

        // ---------------------------------------------------------------------
        // STEP 6: Wrap-up summary
        // ---------------------------------------------------------------------
        $this->newLine();
        $this->info("Polygon.io ticker overview ingestion pipeline initialized successfully.");
        $this->comment("Monitor progress via 'php artisan queue:monitor' or check logs under storage/logs/ingest.log");

        return Command::SUCCESS;
    }
}