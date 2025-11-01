<?php

namespace App\Jobs;

use App\Models\Ticker;
use App\Services\PolygonIndicatorsService;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Class IngestTickerIndicatorsJob
 *
 * A queued job that ingests raw technical indicator data
 * from the Polygon.io API for one or more tickers.
 *
 * Overview:
 * - Each job processes a batch of ticker IDs for specific indicators.
 * - It invokes the PolygonIndicatorsService for each ticker individually.
 * - Supports execution as part of distributed batches (Bus::batch()).
 *
 * Example usage:
 *   Bus::batch([
 *       new IngestTickerIndicatorsJob([1, 2, 3], ['sma_20','ema_50'])
 *   ])->dispatch();
 *
 * Design notes:
 * - All network I/O is isolated in the service layer.
 * - This job is network-bound, so concurrency can be high.
 * - Logging is routed to the 'ingest' channel for consistency.
 */
class IngestTickerIndicatorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /** @var array<int> */
    protected array $tickerIds;

    /** @var array<string> */
    protected array $indicators;

    /** @var array{from?:string,to?:string} */
    protected array $range;

    /** @var int Maximum retry attempts */
    public int $tries = 3;

    /** @var int Seconds to wait before retrying */
    public int $backoff = 30;

    /**
     * @param array<int> $tickerIds
     * @param array<string> $indicators
     * @param array{from?:string,to?:string} $range
     */
    public function __construct(array $tickerIds = [], array $indicators = [], array $range = [])
    {
        // Defensive initialization ensures non-null before serialization
        $this->tickerIds  = $tickerIds ?: [];
        $this->indicators = $indicators ?: [];
        $this->range      = $range ?: [];
    }

    /**
     * Execute the ingestion job for all tickers in this batch.
     */
    public function handle(PolygonIndicatorsService $service): void
    {
        // Batch safety check — avoids "pendingJobs on null" errors
        if (method_exists($this, 'batch') && $this->batch()?->cancelled()) {
            Log::channel('ingest')->warning('⏭️ Skipping IngestTickerIndicatorsJob because the batch was cancelled or missing.', [
                'class' => static::class,
                'ticker_ids' => $this->tickerIds,
            ]);
            return;
        }

        // Defensive reinitialization after unserialization
        $tickerIds  = $this->tickerIds ?? [];
        $indicators = $this->indicators ?? [];
        $range      = $this->range ?? [];

        // Job start log marker
        Log::channel('ingest')->info('🚀 Starting IngestTickerIndicatorsJob', [
            'ticker_count' => count($tickerIds),
            'indicators' => $indicators,
            'range' => $range,
            'batch_id' => $this->batchId ?? null,
        ]);

        // Fetch all tickers by ID
        $tickers = Ticker::whereIn('id', $tickerIds)->get(['id', 'ticker']);

        foreach ($tickers as $ticker) {
            $symbol = $ticker->ticker;

            // Begin ticker-level ingestion
            Log::channel('ingest')->info("🌐 Fetching indicators from Polygon", [
                'ticker' => $symbol,
                'indicators' => $indicators,
                'range' => $range,
            ]);

            try {
                $service->fetchIndicators($symbol, $indicators, $range);

                // Success marker for this ticker
                Log::channel('ingest')->info("✅ Polygon indicators ingested successfully", [
                    'ticker' => $symbol,
                    'ticker_id' => $ticker->id,
                    'indicators' => $indicators,
                ]);
            } catch (Throwable $e) {
                // ❌ Handle any failures gracefully with detailed logging
                Log::channel('ingest')->error("❌ Polygon indicator ingestion failed", [
                    'ticker' => $symbol,
                    'ticker_id' => $ticker->id,
                    'indicators' => $indicators,
                    'message' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 800),
                ]);

                // Rethrow to mark the job failed for Laravel's retry handling
                throw $e;
            }
        }

        // Job complete marker
        Log::channel('ingest')->info("🏁 IngestTickerIndicatorsJob finished successfully", [
            'batch_id' => $this->batchId ?? null,
            'tickers_processed' => count($tickerIds),
            'indicators' => $indicators,
        ]);
    }

    /**
     * Tags for Laravel Horizon monitoring / job grouping.
     */
    public function tags(): array
    {
        return [
            'ingest',
            'polygon',
            'resolution:1d',
            'batch:' . ($this->batchId ?? 'none'),
            'tickers:' . implode(',', $this->tickerIds),
        ];
    }
}