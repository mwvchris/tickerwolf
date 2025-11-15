<?php

namespace App\Jobs;

use App\Services\PolygonTickerPriceHistoryService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 *  IngestTickerPriceHistoryJob (v3.0.0)
 * ============================================================================
 *
 *  MULTI-TICKER + MULTI-RESOLUTION (1d + 1h) PRICE HISTORY INGESTION
 *
 *  ✔ Handles MANY tickers per job (100–500 depending on batch size)
 *  ✔ Auto-from per ticker using DB latest-t + redundancy
 *  ✔ Fetches BOTH daily + hourly bars in same job
 *  ✔ Bulk inserts via PolygonTickerPriceHistoryService
 *  ✔ Hourly retention purge (default: 168 hours)
 *  ✔ Ultra-safe logging & error isolation (ticker-level try/catch)
 *
 *  Payload Input:
 *      $tickers = [
 *         [id => 24, ticker => 'AAPL', type => 'cs'],
 *         [id => ...],
 *         ...
 *      ]
 *
 *  NOTE: No memory blow-up — each ticker is processed independently.
 * ============================================================================
 */
class IngestTickerPriceHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /**
     * @var array<int, array{id:int,ticker:string,type:?string}>
     */
    protected array $tickers;

    protected string $resolutionMode; // "1d", "1h", or "both"
    protected string $globalFrom;
    protected string $toDate;

    protected int $windowDays;       // Daily auto-from lookback
    protected int $redundancyDays;   // Daily overlap
    protected int $redundancyHours;  // Hourly overlap
    protected int $retentionHours;   // Retention cutoff for 1h

    /**
     * @param array<int,array{id:int,ticker:string,type:?string}> $tickers
     */
    public function __construct(
        array $tickers,
        string $resolutionMode,
        string $globalFrom,
        string $toDate,
        int $windowDays,
        int $redundancyDays,
        int $redundancyHours,
        int $retentionHours
    ) {
        $this->tickers         = $tickers;
        $this->resolutionMode  = $resolutionMode;
        $this->globalFrom      = $globalFrom;
        $this->toDate          = $toDate;
        $this->windowDays      = $windowDays;
        $this->redundancyDays  = $redundancyDays;
        $this->redundancyHours = $redundancyHours;
        $this->retentionHours  = $retentionHours;
    }

    /**
     * Execute the multi-ticker job.
     */
    public function handle(): void
    {
        $service = App::make(PolygonTickerPriceHistoryService::class);
        $logger  = Log::channel('ingest');

        $batchId = $this->batchId ?? 'n/a';

        $logger->info("🚀 Multi-ticker price-history batch started", [
            'batch_id'        => $batchId,
            'tickers_count'   => count($this->tickers),
            'resolution_mode' => $this->resolutionMode,
        ]);

        $processed  = 0;
        $succeeded  = 0;
        $failed     = 0;

        foreach ($this->tickers as $t) {
            $tickerId = $t['id'];
            $symbol   = $t['ticker'];

            try {
                $this->processOneTicker(
                    $service,
                    $symbol,
                    $tickerId
                );

                $succeeded++;
            } catch (Throwable $e) {
                $failed++;

                $logger->error('❌ Failed ticker ingestion', [
                    'ticker'   => $symbol,
                    'ticker_id'=> $tickerId,
                    'batch_id' => $batchId,
                    'error'    => $e->getMessage(),
                    'trace'    => substr($e->getTraceAsString(), 0, 500),
                ]);
            }

            $processed++;

            if ($processed % 25 === 0 || $processed === count($this->tickers)) {
                $logger->info("📊 Ingestion batch progress", [
                    'batch_id'  => $batchId,
                    'processed' => $processed,
                    'succeeded' => $succeeded,
                    'failed'    => $failed,
                ]);
            }
        }

        // Global hourly retention purge (only once per job)
        try {
            $cutoff = Carbon::now()->subHours($this->retentionHours);
            $deleted = $service->purgeOld1h($cutoff);

            $logger->info("🧹 Hourly retention purge completed", [
                'batch_id'       => $batchId,
                'cutoff'         => $cutoff->toDateTimeString(),
                'deleted_rows'   => $deleted,
                'retentionHours' => $this->retentionHours,
            ]);
        } catch (Throwable $e) {
            $logger->error("❌ 1h retention purge failed", [
                'batch_id' => $batchId,
                'error'    => $e->getMessage(),
            ]);
        }

        $logger->info("✅ Multi-ticker batch complete", [
            'batch_id'  => $batchId,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed'    => $failed,
        ]);
    }

    /**
     * Process a single ticker (1d + 1h resolution).
     */
    protected function processOneTicker(
        PolygonTickerPriceHistoryService $service,
        string $symbol,
        int $tickerId
    ): void {
        $logger = Log::channel('ingest');

        $logger->info("📈 Processing ticker", [
            'symbol'    => $symbol,
            'ticker_id' => $tickerId,
        ]);

        $globalFrom = Carbon::parse($this->globalFrom);
        $toDate     = Carbon::parse($this->toDate)->endOfDay();

        // ---------------------------------------------------------------------
        // DAILY AUTO-FROM
        // ---------------------------------------------------------------------
        $latestDaily = $service->getLatestTimestamp($tickerId, '1d');
        $dailyFrom = $latestDaily
            ? Carbon::parse($latestDaily)->subDays($this->redundancyDays)
            : $globalFrom->copy();

        if ($dailyFrom->lt($globalFrom)) {
            $dailyFrom = $globalFrom->copy();
        }

        // Bound the auto-from window
        $minDaily = Carbon::now()->subDays($this->windowDays);
        if ($dailyFrom->lt($minDaily)) {
            $dailyFrom = $minDaily;
        }

        // ---------------------------------------------------------------------
        // HOURLY AUTO-FROM
        // ---------------------------------------------------------------------
        $latestHourly = $service->getLatestTimestamp($tickerId, '1h');
        $hourlyFrom = $latestHourly
            ? Carbon::parse($latestHourly)->subHours($this->redundancyHours)
            : Carbon::now()->subDays(7)->startOfDay(); // fallback: 7-day lookback

        // ---------------------------------------------------------------------
        // FETCH DAILY BARS
        // ---------------------------------------------------------------------
        if ($this->resolutionMode === '1d' || $this->resolutionMode === 'both') {
            $service->fetchAndStoreBars(
                $symbol,
                $tickerId,
                '1d',
                $dailyFrom,
                $toDate
            );
        }

        // ---------------------------------------------------------------------
        // FETCH HOURLY BARS
        // ---------------------------------------------------------------------
        if ($this->resolutionMode === '1h' || $this->resolutionMode === 'both') {
            $service->fetchAndStoreBars(
                $symbol,
                $tickerId,
                '1h',
                $hourlyFrom,
                $toDate
            );
        }

        $logger->info("✅ Completed ticker", [
            'symbol'        => $symbol,
            'ticker_id'     => $tickerId,
            'daily_from'    => $dailyFrom->toDateString(),
            'hourly_from'   => $hourlyFrom->toDateTimeString(),
        ]);
    }
}