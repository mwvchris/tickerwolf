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

class IngestTickerOverviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    protected array $tickers;

    public function __construct(array $tickers)
    {
        $this->tickers = $tickers;
    }

    public function handle(): void
    {
        $service = App::make(PolygonTickerOverviewService::class);
        $batchId = $this->batchId ?? 'n/a';
        $total = count($this->tickers);

        Log::channel('polygon')->info("Processing ticker overview batch", [
            'batch_id' => $batchId,
            'total_tickers' => $total,
        ]);

        $processed = $succeeded = $failed = 0;

        foreach ($this->tickers as $ticker) {
            try {
                $service->fetchAndUpsertOverview($ticker);
                $succeeded++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel('polygon')->error("Failed to process ticker overview", [
                    'ticker' => $ticker,
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;

            if ($processed % 50 === 0 || $processed === $total) {
                Log::channel('polygon')->info("Batch progress", [
                    'batch_id' => $batchId,
                    'processed' => $processed,
                    'succeeded' => $succeeded,
                    'failed' => $failed,
                ]);
            }
        }

        Log::channel('polygon')->info("Batch complete", [
            'batch_id' => $batchId,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ]);
    }
}