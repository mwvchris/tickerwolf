<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Jobs\IngestTickerOverviewJob;
use App\Models\Ticker;
use Throwable;

/**
 * ============================================================================
 *  polygon:ticker-overviews:ingest
 *  (v2.1.0 — Streamed, Memory-Safe Batching + Safer Defaults)
 * ============================================================================
 *
 * 🔧 Purpose
 * ----------------------------------------------------------------------------
 * Dispatch queued jobs to ingest Polygon.io ticker overviews for the current
 * ticker universe. Uses a streaming cursor + buffered chunking so we never
 * load all tickers into memory at once.
 *
 * 🧠 High-Level Behavior
 * ----------------------------------------------------------------------------
 *  • Streams tickers from the DB using cursor() (no huge in-memory array).
 *  • Buffers tickers into "dispatch batches" (size = --batch).
 *  • Within each dispatch batch, splits into multiple queue jobs
 *      (size = --per-job) so each job does a small, bounded unit of work.
 *  • Enqueues Bus batches on the `database` queue connection.
 *  • Plays nicely with your `queue:supervisor` / docker queue worker.
 *
 * 📦 Typical Usage
 * ----------------------------------------------------------------------------
 *  # Nightly / safe mode
 *  php artisan polygon:ticker-overviews:ingest
 *
 *  # Faster, but still safe-ish (what tickers:refresh-all --fast / --dev use)
 *  php artisan polygon:ticker-overviews:ingest --batch=1500 --per-job=75 --sleep=0
 *
 * ⚙️ Options
 * ----------------------------------------------------------------------------
 *  --limit       : Max number of tickers to process (0 = all).
 *  --batch       : Number of tickers to buffer *per Bus batch dispatch*.
 *                  This controls how many tickers we process per round-trip
 *                  to the queue system.
 *  --per-job     : Number of tickers each queue job will handle.
 *                  Lower = smaller runtime and memory footprint per job.
 *  --sleep       : Seconds to sleep between Bus batch dispatches.
 *
 * 🧪 Safety / Memory Notes
 * ----------------------------------------------------------------------------
 *  • Uses cursor() to stream, not ->get().
 *  • Bounds per-job work via --per-job (defaults conservative).
 *  • You can tune --batch and --per-job separately to match hardware.
 *
 * 🧩 Related
 * ----------------------------------------------------------------------------
 *  • App\Jobs\IngestTickerOverviewJob
 *  • docker `tickerwolf-queue` worker + queue:supervisor
 *  • tickers:refresh-all umbrella command
 * ============================================================================
 */
class PolygonTickerOverviewsIngest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * NOTE:
     *  • We keep --batch and --sleep for backward compatibility
     *  • We keep --per-job for fine-tuning job runtime
     */
    protected $signature = 'polygon:ticker-overviews:ingest
                            {--limit=0 : Limit total tickers processed (0 = all)}
                            {--batch=1200 : Number of tickers per Bus batch dispatch}
                            {--per-job=75 : Number of tickers per queue job}
                            {--sleep=1 : Seconds to sleep between Bus batches}';

    /**
     * The console command description.
     */
    protected $description = 'Ingest Polygon.io ticker overviews in streamed, memory-safe batches using queued jobs.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // ---------------------------------------------------------------------
        // 1️⃣ Resolve options and log context
        // ---------------------------------------------------------------------
        $limit         = (int) $this->option('limit');
        $batchSize     = max(1, (int) $this->option('batch'));     // total tickers per Bus batch
        $tickersPerJob = max(1, (int) $this->option('per-job'));   // tickers handled by each job
        $sleep         = max(0, (int) $this->option('sleep'));     // pause between dispatches

        $logger = Log::channel('ingest');

        $logger->info('📥 Polygon ticker-overviews ingestion command started', [
            'limit'           => $limit,
            'batch_size'      => $batchSize,
            'tickers_per_job' => $tickersPerJob,
            'sleep'           => $sleep,
        ]);

        $this->info('📊 Polygon Ticker Overviews Ingestion (v2.1.0)');
        $this->line('   Limit          : ' . ($limit ?: 'ALL'));
        $this->line("   Bus batch size : {$batchSize} tickers");
        $this->line("   Tickers / job  : {$tickersPerJob}");
        $this->line("   Sleep between  : {$sleep} second(s)");
        $this->newLine();

        // ---------------------------------------------------------------------
        // 2️⃣ Build base ticker query (active universe)
        // ---------------------------------------------------------------------
        $tickerQuery = Ticker::query()
            ->where('is_active_polygon', true)
            ->orderBy('id')
            ->select(['id', 'ticker']);

        if ($limit > 0) {
            $tickerQuery->limit($limit);
        }

        $total = (clone $tickerQuery)->count();

        if ($total === 0) {
            $this->warn('⚠️ No tickers found for Polygon overview ingestion.');
            $logger->warning('⚠️ No tickers found for Polygon overview ingestion.', [
                'limit' => $limit,
            ]);
            return Command::SUCCESS;
        }

        $this->info("🔢 Found {$total} ticker(s) to process.");
        $this->newLine();

        // ---------------------------------------------------------------------
        // 3️⃣ Stream tickers via cursor and dispatch in buffered Bus batches
        // ---------------------------------------------------------------------
        $buffer        = [];
        $dispatched    = 0;
        $dispatchIndex = 1;

        // Thin progress bar: total tickers we'll *attempt* to dispatch.
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat("   🟢 Dispatch: %current%/%max% [%bar%] %percent:3s%%");
        $bar->start();

        foreach ($tickerQuery->cursor() as $ticker) {
            $buffer[] = [
                'id'     => $ticker->id,
                'ticker' => $ticker->ticker,
            ];

            // If buffer reached the desired Bus batch size, dispatch it.
            if (count($buffer) >= $batchSize) {
                $this->dispatchBufferAsBusBatch(
                    $buffer,
                    $tickersPerJob,
                    $dispatchIndex,
                    $logger
                );

                $dispatched += count($buffer);
                $bar->advance(count($buffer));

                $buffer = []; // Clear buffer for next run
                $dispatchIndex++;

                if ($sleep > 0) {
                    $this->newLine();
                    $this->line("⏳ Sleeping {$sleep} second(s) before next batch...");
                    sleep($sleep);
                }
            }
        }

        // Flush any remaining tickers in the buffer.
        if (! empty($buffer)) {
            $this->dispatchBufferAsBusBatch(
                $buffer,
                $tickersPerJob,
                $dispatchIndex,
                $logger
            );

            $dispatched += count($buffer);
            $bar->advance(count($buffer));

            $dispatchIndex++;
        }

        $bar->finish();
        $this->newLine(2);

        // ---------------------------------------------------------------------
        // 4️⃣ Final summary
        // ---------------------------------------------------------------------
        $this->info("🏁 Polygon ticker overview ingestion batches dispatched.");
        $this->line("   Tickers targeted : {$total}");
        $this->line("   Tickers buffered : {$dispatched}");
        $this->line("   Bus batches sent : " . ($dispatchIndex - 1));
        $this->newLine();

        $logger->info('🏁 Polygon ticker overviews ingestion batches dispatched', [
            'total_tickers'      => $total,
            'dispatched_tickers' => $dispatched,
            'bus_batches'        => $dispatchIndex - 1,
            'batch_size'         => $batchSize,
            'tickers_per_job'    => $tickersPerJob,
            'sleep'              => $sleep,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Dispatch a buffer of tickers as a Bus batch, splitting into jobs of size $tickersPerJob.
     *
     * @param  array<int, array{id:int,ticker:string}>  $buffer
     * @param  int                                      $tickersPerJob
     * @param  int                                      $dispatchIndex
     * @param  \Illuminate\Support\Facades\Log          $logger
     * @return void
     */
    protected function dispatchBufferAsBusBatch(
        array $buffer,
        int $tickersPerJob,
        int $dispatchIndex,
        $logger
    ): void {

        $jobs = [];
        $chunks = array_chunk($buffer, $tickersPerJob);

        foreach ($chunks as $chunkIndex => $chunk) {

            // FIX: extract only ticker strings
            $tickersOnly = [];
            foreach ($chunk as $row) {
                if (isset($row['ticker'])) {
                    $tickersOnly[] = $row['ticker'];
                }
            }

            if (empty($tickersOnly)) {
                $logger->warning('⚠️ Skipping empty ticker chunk', [
                    'dispatch_index' => $dispatchIndex,
                    'chunk_index'    => $chunkIndex,
                ]);
                continue;
            }

            $jobs[] = new IngestTickerOverviewJob($tickersOnly);
        }

        if (empty($jobs)) {
            return;
        }

        try {
            $batch = Bus::batch($jobs)
                ->name("PolygonTickerOverviewsIngest (Dispatch #{$dispatchIndex})")
                ->onConnection('database')
                ->onQueue('default')
                ->dispatch();

            $logger->info('✅ Dispatched Polygon ticker-overviews Bus batch', [
                'dispatch_index' => $dispatchIndex,
                'batch_id'       => $batch->id ?? null,
                'job_count'      => count($jobs),
                'tickers_count'  => count($buffer),
            ]);

            $this->info("✅ Dispatched Bus batch #{$dispatchIndex} — "
                . count($jobs) . " job(s), "
                . count($buffer) . " ticker(s)");

        } catch (Throwable $e) {

            $logger->error('❌ Failed to dispatch Polygon ticker-overviews Bus batch', [
                'dispatch_index' => $dispatchIndex,
                'error'          => $e->getMessage(),
                'trace'          => substr($e->getTraceAsString(), 0, 400),
            ]);

            $this->error("❌ Failed to dispatch Bus batch #{$dispatchIndex}: {$e->getMessage()}");
        }
    }
}