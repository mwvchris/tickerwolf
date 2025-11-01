<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Ticker;
use App\Jobs\ProcessTickerOverviewBatch;
use Throwable;

class PolygonTickerOverviewsIngest extends Command
{
    protected $signature = 'polygon:ticker-overviews:ingest
                            {--batch=500 : Number of tickers per job batch}
                            {--sleep=5 : Seconds to sleep between batch dispatches}';

    protected $description = 'Ingest Polygon.io ticker overviews in batches using queue jobs';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $sleep = (int) $this->option('sleep');

        $this->info("Preparing to dispatch batch jobs...");

        $totalTickers = Ticker::count();
        if ($totalTickers === 0) {
            $this->warn("No tickers found in the database.");
            return Command::SUCCESS;
        }

        $this->info("Total tickers: {$totalTickers}");
        $this->info("Processing: {$totalTickers} tickers, batch size {$batchSize}");

        $jobs = [];
        $tickers = Ticker::select('id', 'ticker')->orderBy('id')->cursor();

        $chunk = [];
        foreach ($tickers as $ticker) {
            $chunk[] = $ticker;

            if (count($chunk) >= $batchSize) {
                $jobs[] = new ProcessTickerOverviewBatch($chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $jobs[] = new ProcessTickerOverviewBatch($chunk);
        }

        if (empty($jobs)) {
            $this->warn("No jobs to dispatch.");
            return Command::SUCCESS;
        }

        // 🚀 Create a batch using proper Carbon datetime, not integer timestamp
        $batch = Bus::batch($jobs)
            ->name('PolygonTickerOverviews')
            ->then(function (Batch $batch) {
                Log::info("Polygon overview ingestion batch complete", [
                    'batch_id' => $batch->id,
                    'total_jobs' => $batch->totalJobs,
                ]);
            })
            ->catch(function (Batch $batch, Throwable $e) {
                Log::error("Polygon overview ingestion batch failed", [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            })
            ->finally(function (Batch $batch) {
                Log::info("Polygon overview ingestion batch finished", [
                    'batch_id' => $batch->id,
                    'finished_jobs' => $batch->processedJobs(),
                ]);
            })
            ->dispatch();

        $this->info("Batch dispatched: {$batch->id}");
        $this->info("Queued {$batch->totalJobs} jobs.");

        return Command::SUCCESS;
    }
}