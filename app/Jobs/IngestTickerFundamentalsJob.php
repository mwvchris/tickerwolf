<?php

namespace App\Jobs;

use App\Models\Ticker;
use App\Services\PolygonFundamentalsService;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestTickerFundamentalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $tickerId;
    public array $options;

    public int $tries = 3;
    public int $backoff = 30; // seconds

    /**
     * @param int   $tickerId
     * @param array $options  e.g. ['order' => 'desc', 'limit' => 100, 'timeframe' => 'quarterly']
     */
    public function __construct(int $tickerId, array $options = [])
    {
        $this->tickerId = $tickerId;
        $this->options  = $options;
    }

    public function handle(PolygonFundamentalsService $service): void
    {
        $ticker = Ticker::find($this->tickerId);

        if (! $ticker) {
            Log::channel('ingest')->warning("⚠️ Ticker not found for fundamentals ingestion", [
                'ticker_id' => $this->tickerId,
            ]);
            return;
        }

        $symbol = $ticker->ticker;
        Log::channel('ingest')->info("▶️ Fundamentals job starting", [
            'symbol' => $symbol,
            'ticker_id' => $this->tickerId,
            'options' => $this->options,
        ]);

        try {
            // --- Phase 1: Begin API call
            Log::channel('ingest')->info("🌐 Calling PolygonFundamentalsService::fetchAndStoreFundamentals()", [
                'symbol' => $symbol,
            ]);

            $count = $service->fetchAndStoreFundamentals($symbol, $this->options);

            // --- Phase 2: Validate response
            if (is_array($count)) {
                Log::channel('ingest')->warning("⚠️ Service returned array instead of count", [
                    'symbol' => $symbol,
                    'response_type' => gettype($count),
                    'keys' => array_keys($count),
                ]);
            }

            if (is_numeric($count)) {
                Log::channel('ingest')->info("✅ Fundamentals service completed successfully", [
                    'symbol' => $symbol,
                    'records_inserted_or_updated' => (int) $count,
                ]);
            } else {
                Log::channel('ingest')->warning("⚠️ Fundamentals service returned unexpected result", [
                    'symbol' => $symbol,
                    'result_type' => gettype($count),
                    'value' => $count,
                ]);
            }

            Log::channel('ingest')->info("🏁 Fundamentals job complete", [
                'symbol' => $symbol,
                'ticker_id' => $this->tickerId,
            ]);

        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ Fundamentals job failed", [
                'symbol' => $symbol,
                'ticker_id' => $this->tickerId,
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 1000), // limit trace length
            ]);
            throw $e; // Let the queue handle retries
        }
    }

    public function tags(): array
    {
        return [
            'fundamentals',
            'ticker:' . ($this->tickerId ?? 'unknown'),
        ];
    }
}