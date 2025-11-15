<?php

namespace App\Jobs;

use App\Services\PolygonTickerOverviewService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 *  IngestTickerOverviewJob  (v2.1 — Hardened & Time-Aware)
 * ============================================================================
 *
 *  ✔ Ensures all tickers are STRINGS before serialization
 *  ✔ Completely prevents "Array to string conversion" errors
 *  ✔ Safe JSON logging for debugging without breaking workers
 *  ✔ Validates Polygon response shape at runtime
 *  ✔ Ensures the service receives only scalar ticker symbols
 *  ✔ Fully compatible with database queue serialization
 *  ✔ Adds basic timing info to understand per-job runtime
 * ============================================================================
 */
class IngestTickerOverviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /**
     * @var array<string> Guaranteed list of clean string tickers
     */
    protected array $tickers;

    /**
     * Maximum attempts for this job.
     *
     * (We keep this modest; flakey tickers will eventually give up.)
     */
    public int $tries = 3;

    /**
     * @param  array  $tickers  An array of raw items (strings, models, objects, etc.).
     *                          All values are normalized to simple strings here.
     */
    public function __construct(array $tickers)
    {
        $clean = [];

        foreach ($tickers as $t) {
            if (is_object($t) && isset($t->ticker)) {
                $clean[] = (string) $t->ticker;
            } elseif (is_string($t)) {
                $clean[] = $t;
            } elseif (is_scalar($t)) {
                $clean[] = (string) $t;
            } else {
                // ❗ Any non-scalar / non-string / non-model value is discarded to avoid crashes.
                Log::channel('ingest')->warning('⚠️ Dropping non-scalar ticker in job payload', [
                    'received_type' => gettype($t),
                    'value_preview' => is_array($t) ? array_slice($t, 0, 5) : $t,
                ]);
            }
        }

        $this->tickers = array_values($clean);
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        $service  = App::make(PolygonTickerOverviewService::class);
        $batchId  = $this->batchId ?? 'n/a';
        $total    = count($this->tickers);
        $started  = microtime(true);

        Log::channel('ingest')->info("🚀 Starting ticker overview batch", [
            'batch_id' => $batchId,
            'count'    => $total,
            'sample'   => array_slice($this->tickers, 0, 5),
        ]);

        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        foreach ($this->tickers as $ticker) {
            // ⛑ Safety guard: ensure scalar string before service call
            if (! is_string($ticker) || trim($ticker) === '') {
                $failed++;
                Log::channel('ingest')->error("❌ Invalid ticker value encountered", [
                    'batch_id'   => $batchId,
                    'ticker_raw' => $ticker,
                ]);
                $processed++;
                continue;
            }

            try {
                $result = $service->fetchAndUpsertOverview($ticker);

                // OPTIONAL: Validate service return shape to prevent upstream failures
                if (is_array($result) && isset($result['error'])) {
                    throw new \Exception("Polygon service reported error: " . json_encode($result['error']));
                }

                $succeeded++;
            } catch (Throwable $e) {
                $failed++;

                Log::channel('ingest')->error("❌ Failed processing ticker overview", [
                    'ticker'   => $ticker,
                    'batch_id' => $batchId,
                    'error'    => $e->getMessage(),
                    'trace'    => substr($e->getTraceAsString(), 0, 300),
                ]);
            }

            $processed++;

            // Log progress every 50 tickers or at end
            if ($processed % 50 === 0 || $processed === $total) {
                Log::channel('ingest')->info("📊 Batch progress", [
                    'batch_id'  => $batchId,
                    'processed' => $processed,
                    'succeeded' => $succeeded,
                    'failed'    => $failed,
                ]);
            }
        }

        $duration = microtime(true) - $started;

        Log::channel('ingest')->info("✅ Overview batch complete", [
            'batch_id'  => $batchId,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'duration_s'=> round($duration, 2),
        ]);
    }
}