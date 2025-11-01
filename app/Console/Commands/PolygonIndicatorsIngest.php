<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Jobs\IngestTickerIndicatorsJob;
use Throwable;

class PolygonIndicatorsIngest extends Command
{
    protected $signature = 'polygon:indicators:ingest
        {--tickers= : Comma-separated list of tickers (defaults to active universe)}
        {--indicators=sma_20,ema_50,ema_200,rsi_14,macd : Comma-separated indicator list}
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD)}
        {--batch=200 : Tickers per job}
        {--sleep=0 : Seconds to sleep between batches}
        {--only-active : Only active tickers (default)}
        {--include-inactive : Include inactive tickers}
    ';

    protected $description = 'Ingest Polygon indicators (SMA/EMA/RSI/MACD) for tickers over a date range.';

    public function handle(): int
    {
        $tickersArg   = $this->option('tickers');
        $indicators   = array_values(array_filter(array_map('trim', explode(',', (string)$this->option('indicators')))));
        $from         = $this->option('from');
        $to           = $this->option('to');
        $batchSize    = (int) $this->option('batch');
        $sleepSeconds = (int) $this->option('sleep');

        Log::channel('ingest')->info("▶️ PolygonIndicatorsIngest command starting", [
            'indicators' => $indicators,
            'from' => $from,
            'to' => $to,
            'batch_size' => $batchSize,
            'sleep_seconds' => $sleepSeconds,
        ]);

        try {
            // --- Phase 1: Validate indicators
            if (empty($indicators)) {
                Log::channel('ingest')->warning("⚠️ No indicators specified. Aborting.");
                $this->error('No indicators specified.');
                return self::FAILURE;
            }

            // --- Phase 2: Select tickers
            if ($tickersArg) {
                $tickersList = array_values(array_filter(array_map('trim', explode(',', $tickersArg))));
                $tickerIds = Ticker::whereIn('ticker', $tickersList)->pluck('id')->all();

                Log::channel('ingest')->info("📈 Ticker subset provided", [
                    'tickers' => $tickersList,
                    'count' => count($tickerIds),
                ]);
            } else {
                $q = Ticker::query();
                if (!$this->option('include-inactive')) {
                    $q->where('active', true);
                }
                $tickerIds = $q->pluck('id')->all();

                Log::channel('ingest')->info("📊 Auto-selected active tickers", [
                    'count' => count($tickerIds),
                ]);
            }

            if (empty($tickerIds)) {
                Log::channel('ingest')->warning("⚠️ No tickers found to process");
                $this->warn('No tickers found to process.');
                return self::SUCCESS;
            }

            $range = [];
            if ($from) $range['from'] = $from;
            if ($to)   $range['to']   = $to;

            // --- Phase 3: Chunk tickers
            $chunks     = array_chunk($tickerIds, max(1, $batchSize));
            $batchJobs  = [];
            $chunkCount = count($chunks);

            Log::channel('ingest')->info("🧩 Preparing indicator ingestion jobs", [
                'chunks' => $chunkCount,
                'total_tickers' => count($tickerIds),
                'indicators' => $indicators,
            ]);

            foreach ($chunks as $i => $chunk) {
                $batchJobs[] = new IngestTickerIndicatorsJob($chunk, $indicators, $range);

                Log::channel('ingest')->info("🧱 Queued chunk", [
                    'chunk_number' => $i + 1,
                    'tickers_in_chunk' => count($chunk),
                    'range' => $range,
                ]);

                if ($sleepSeconds > 0 && $i < ($chunkCount - 1)) {
                    Log::channel('ingest')->info("💤 Sleeping before next batch", [
                        'sleep_seconds' => $sleepSeconds,
                    ]);
                    sleep($sleepSeconds);
                }
            }

            // --- Phase 4: Dispatch batch
            $batch = Bus::batch($batchJobs)
                ->name('polygon:indicators:ingest [' . now()->toDateTimeString() . ']')
                ->allowFailures()
                ->dispatch();

            Log::channel('ingest')->info("🚀 Indicators ingestion batch dispatched", [
                'batch_id' => $batch->id,
                'total_jobs' => $batch->totalJobs,
                'timestamp' => now()->toDateTimeString(),
            ]);

            Log::channel('ingest')->info("🏁 PolygonIndicatorsIngest command complete", [
                'batch_id' => $batch->id,
            ]);

            $this->info("Dispatched indicators batch id={$batch->id} ({$batch->totalJobs} jobs)");

            return self::SUCCESS;

        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ PolygonIndicatorsIngest command failed", [
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 1000),
            ]);
            throw $e;
        }
    }
}