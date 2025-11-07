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
 * Job: IngestTickerOverviewJob
 *
 * Processes a batch of ticker symbols by fetching their overview data
 * from Polygon.io and upserting into the local DB.
 *
 * Note: This job is dispatched in batches by PolygonTickerOverviewsIngest.
 * Each instance should only contain scalar ticker symbols (strings) to
 * ensure serialization into the database queue works properly.
 */
class IngestTickerOverviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /**
     * @var array List of ticker symbols (strings only)
     */
    protected $tickers;

    /**
     * @param array $tickers Plain array of ticker symbols, e.g. ['AAPL','MSFT']
     */
    public function __construct(array $tickers)
    {
        // Convert possible Eloquent models or objects to strings for safe serialization
        $this->tickers = array_map(function ($t) {
            if (is_object($t) && isset($t->ticker)) {
                return $t->ticker;
            }
            return (string) $t;
        }, $tickers);
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        $service = App::make(PolygonTickerOverviewService::class);
        $batchId = $this->batchId ?? 'n/a';
        $total = count($this->tickers);

        Log::channel('ingest')->info("🚀 Processing ticker overview batch", [
            'batch_id' => $batchId,
            'total_tickers' => $total,
            'tickers_sample' => array_slice($this->tickers, 0, 5),
        ]);

        $processed = $succeeded = $failed = 0;

        foreach ($this->tickers as $ticker) {
            try {
                $service->fetchAndUpsertOverview($ticker);
                $succeeded++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel('ingest')->error("❌ Failed to process ticker overview", [
                    'ticker' => $ticker,
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;
            if ($processed % 50 === 0 || $processed === $total) {
                Log::channel('ingest')->info("📊 Batch progress", [
                    'batch_id' => $batchId,
                    'processed' => $processed,
                    'succeeded' => $succeeded,
                    'failed' => $failed,
                ]);
            }
        }

        Log::channel('ingest')->info("✅ Batch complete", [
            'batch_id' => $batchId,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ]);
    }
}