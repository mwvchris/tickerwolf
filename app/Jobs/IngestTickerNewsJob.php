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

class IngestTickerNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $tickerId;
    public int $limit;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(int $tickerId, int $limit = 50)
    {
        $this->tickerId = $tickerId;
        $this->limit = $limit;
    }

    public function handle(PolygonTickerNewsService $service): void
    {
        $ticker = Ticker::find($this->tickerId);

        if (! $ticker) {
            Log::warning("⚠️ Ticker not found for news ingestion: ID {$this->tickerId}");
            return;
        }

        try {
            $count = $service->fetchNewsForTicker($ticker->ticker, $this->limit);
            Log::channel('ingest')->info("📰 Ingested {$count} news items for {$ticker->ticker}");

            // Batch monitoring update
            if ($batch = $this->batch()) {
                if ($jobBatch = JobBatch::find($batch->id)) {
                    BatchMonitorService::decrementPending($jobBatch);
                }
            }
        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ Failed news ingestion for {$ticker->ticker}: {$e->getMessage()}");

            if ($batch = $this->batch()) {
                if ($jobBatch = JobBatch::find($batch->id)) {
                    BatchMonitorService::markFailed(
                        $jobBatch,
                        $this->job?->uuid() ?? uniqid('job_', true)
                    );
                }
            }

            throw $e;
        }
    }

    public function tags(): array
    {
        return [
            'ticker:' . ($this->tickerId ?? 'unknown'),
            'news',
        ];
    }
}