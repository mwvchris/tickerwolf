<?php

namespace App\Jobs;

use App\Models\Ticker;
use App\Models\JobBatch;
use App\Services\PolygonTickerNewsService;
use App\Services\BatchMonitorService;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 *  IngestTickerNewsJob (v2.0 — Serialization-Safe Refactor)
 * ============================================================================
 *
 * ❗ WHAT THIS FIXES:
 *   • Removes ALL non-primitive members from the public job payload.
 *   • Prevents "Cannot assign NativeSerializableClosure..." failures.
 *   • Ensures the DB queue payload contains ONLY primitives.
 *
 * PURPOSE:
 *   Fetches & stores Polygon.io news items for a single ticker.
 *   This job is dispatched in large batches by PolygonTickerNewsIngest.
 *
 * SAFE PAYLOAD:
 *   - int $tickerId
 *   - int $limit
 * ============================================================================
 */
class IngestTickerNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /**
     * Strictly primitive-only properties (required for DB queue safety)
     */
    public int $tickerId;
    public int $limit;

    /**
     * Retry behavior
     */
    public int $tries = 3;
    public int $backoff = 30; // seconds

    /**
     * @param int $tickerId  The ID of the ticker (NOT a model instance)
     * @param int $limit     Max number of articles to pull (per API call cycle)
     */
    public function __construct(int $tickerId, int $limit = 50)
    {
        $this->tickerId = $tickerId;
        $this->limit    = $limit;
    }

    /**
     * Execute the job.
     */
    public function handle(PolygonTickerNewsService $service): void
    {
        // Lookup ticker safely inside the job (do NOT store model in payload)
        $ticker = Ticker::find($this->tickerId);

        if (! $ticker) {
            Log::channel('ingest')->warning("⚠️ News job: Ticker not found", [
                'ticker_id' => $this->tickerId,
            ]);
            return;
        }

        $symbol = $ticker->ticker;

        Log::channel('ingest')->info("📰 Starting news ingestion job", [
            'ticker_id' => $this->tickerId,
            'symbol'    => $symbol,
            'limit'     => $this->limit,
        ]);

        try {
            /**
             * Service returns: int $count OR array with details
             */
            $result = $service->fetchNewsForTicker($symbol, $this->limit);

            if (is_numeric($result)) {
                Log::channel('ingest')->info("✅ News ingestion complete", [
                    'symbol'  => $symbol,
                    'count'   => (int) $result,
                ]);
            } else {
                Log::channel('ingest')->warning("⚠️ Unexpected news service result", [
                    'symbol' => $symbol,
                    'type'   => gettype($result),
                    'sample' => is_array($result) ? array_slice($result, 0, 3) : $result,
                ]);
            }

            // -----------------------------
            // BatchMonitorService integration
            // -----------------------------
            if ($batch = $this->batch()) {
                if ($jobBatch = JobBatch::find($batch->id)) {
                    BatchMonitorService::decrementPending($jobBatch);
                }
            }

        } catch (Throwable $e) {

            Log::channel('ingest')->error("❌ News ingestion failed", [
                'symbol'    => $symbol,
                'ticker_id' => $this->tickerId,
                'message'   => $e->getMessage(),
                'trace'     => substr($e->getTraceAsString(), 0, 1000),
            ]);

            // Mark batch job failure
            if ($batch = $this->batch()) {
                if ($jobBatch = JobBatch::find($batch->id)) {
                    BatchMonitorService::markFailed(
                        $jobBatch,
                        $this->job?->uuid() ?? uniqid('news_', true)
                    );
                }
            }

            throw $e; // allow queue retries
        }
    }

    /**
     * Queue job tags (shown in Horizon/UIs)
     */
    public function tags(): array
    {
        return [
            'news',
            'ticker:' . $this->tickerId,
        ];
    }
}