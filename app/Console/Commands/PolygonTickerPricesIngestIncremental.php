<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Ticker;
use App\Services\PolygonPriceHistoryService;
use Carbon\Carbon;

class PolygonTickerPricesIngestIncremental extends Command
{
    protected $signature = 'polygon:ingest-ticker-prices
                            {--ticker= : Specific ticker symbol to ingest}
                            {--force : Reingest full history (default 5 years)}';

    protected $description = 'Incrementally ingest daily ticker prices from Polygon.io into ticker_price_histories with retry and backoff.';

    protected PolygonPriceHistoryService $priceHistoryService;

    public function __construct(PolygonPriceHistoryService $priceHistoryService)
    {
        parent::__construct();
        $this->priceHistoryService = $priceHistoryService;
    }

    public function handle(): int
    {
        $tickerArg = $this->option('ticker');
        $force = $this->option('force');

        $query = Ticker::query();
        if ($tickerArg) {
            $query->where('ticker', strtoupper($tickerArg));
        }

        $tickers = $query->get();
        if ($tickers->isEmpty()) {
            $this->warn('No tickers found matching criteria.');
            return Command::SUCCESS;
        }

        $this->info("Starting incremental ingestion for {$tickers->count()} ticker(s)...");

        foreach ($tickers as $ticker) {
            try {
                $this->ingestTicker($ticker, $force);
            } catch (\Throwable $e) {
                Log::channel('polygon')->error("Error ingesting {$ticker->ticker}: {$e->getMessage()}", [
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("❌ Error ingesting {$ticker->ticker}: {$e->getMessage()}");
            }
        }

        $this->info('✅ All done.');
        return Command::SUCCESS;
    }

    protected function ingestTicker(Ticker $ticker, bool $force = false): void
    {
        $symbol = $ticker->ticker;
        $this->line("→ Processing {$symbol}...");

        $latest = null;
        if (! $force) {
            $latest = DB::table('ticker_price_histories')
                ->where('ticker_id', $ticker->id)
                ->where('resolution', '1d')
                ->max('t');
        }

        $startDate = $force || !$latest
            ? Carbon::now()->subYears(5)->format('Y-m-d')
            : Carbon::parse($latest)->addDay()->format('Y-m-d');

        $endDate = Carbon::now()->format('Y-m-d');

        if (Carbon::parse($startDate)->greaterThan($endDate)) {
            $this->line("   Already up to date.");
            return;
        }

        $this->line("   Fetching bars from {$startDate} → {$endDate} in chunks...");

        $chunkStart = Carbon::parse($startDate);
        $chunkSize = 365;
        $totalBars = 0;

        while ($chunkStart->lessThanOrEqualTo(Carbon::parse($endDate))) {
            $chunkEnd = (clone $chunkStart)->addDays($chunkSize - 1);
            if ($chunkEnd->greaterThan(Carbon::parse($endDate))) {
                $chunkEnd = Carbon::parse($endDate);
            }

            $this->line("     → {$chunkStart->toDateString()} to {$chunkEnd->toDateString()}");

            $attempt = 0;
            $maxAttempts = 3;
            $bars = null;

            while ($attempt < $maxAttempts) {
                try {
                    $bars = $this->priceHistoryService->fetchAggregates(
                        $symbol,
                        1,
                        'day',
                        $chunkStart->toDateString(),
                        $chunkEnd->toDateString()
                    );

                    // Success: exit retry loop
                    break;
                } catch (\Throwable $e) {
                    $attempt++;
                    $wait = pow(2, $attempt + 1);
                    Log::channel('polygon')->warning("Retry {$attempt}/{$maxAttempts} for {$symbol} ({$chunkStart->toDateString()} → {$chunkEnd->toDateString()}): {$e->getMessage()}");
                    $this->warn("       ⚠️ Attempt {$attempt} failed: {$e->getMessage()} — retrying in {$wait}s...");
                    sleep($wait);
                }
            }

            if (!empty($bars)) {
                $inserted = $this->priceHistoryService->upsertBars($ticker->id, $symbol, '1d', $bars);
                $totalBars += $inserted;
                $this->line("       ✅ Upserted {$inserted} bars.");
            } else {
                $this->line("       ❌ Failed all retries or no data returned.");
            }

            // Normal pacing delay to respect rate limits
            sleep(2);
            $chunkStart = $chunkEnd->addDay();
        }

        $this->info("   ✅ Completed {$symbol}: total {$totalBars} bars inserted/updated.");
        Log::channel('polygon')->info("Ingested {$totalBars} bars for {$symbol}", [
            'symbol' => $symbol,
            'start' => $startDate,
            'end' => $endDate,
        ]);
    }
}