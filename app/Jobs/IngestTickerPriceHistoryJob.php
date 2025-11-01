<?php

namespace App\Jobs;

use App\Models\Ticker;
use App\Models\JobBatch;
use App\Services\PolygonTickerPriceHistoryService;
use App\Services\BatchMonitorService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestTickerPriceHistoryJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Ticker $ticker;
    public string $from;
    public string $to;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(Ticker $ticker, string $from = '2019-01-01', ?string $to = null)
    {
        $this->ticker = $ticker;
        $this->from = $from;
        $this->to = $to ?? now()->toDateString();
    }

    public function handle(PolygonTickerPriceHistoryService $service): void
    {
        $symbol = $this->ticker->ticker ?? $this->ticker->symbol ?? 'UNKNOWN';
        $logger = Log::channel('ingest');

        try {
            $logger->info("🚀 Ingesting price history for {$symbol} ({$this->from} → {$this->to})");
            $service->fetchAndStore($this->ticker, $this->from, $this->to);

            if ($batch = $this->batch()) {
                if ($jobBatch = JobBatch::find($batch->id)) {
                    BatchMonitorService::decrementPending($jobBatch);
                }
            }

            $logger->info("✅ Completed ingestion for {$symbol}");
        } catch (Throwable $e) {
            $logger->error("❌ Error ingesting {$symbol}: " . $e->getMessage(), [
                'ticker_id' => $this->ticker->id,
                'trace' => $e->getTraceAsString(),
            ]);

            if ($batch = $this->batch()) {
                if ($jobBatch = JobBatch::find($batch->id)) {
                    BatchMonitorService::markFailed($jobBatch, $this->job?->uuid() ?? uniqid('job_', true));
                }
            }

            throw $e;
        }
    }

    public function tags(): array
    {
        return [
            'ticker:' . ($this->ticker->ticker ?? 'unknown'),
            'price-history',
        ];
    }
}