<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Ticker;
use App\Services\PolygonPriceHistoryService;
use Carbon\Carbon;

/**
 * ============================================================================
 *  polygon:ingest-ticker-prices  (v2.6.4 — Case-Sensitive Incremental Fix)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Incrementally ingests recent Polygon price data for tickers that are missing
 *   bars beyond the most recent locally stored date, avoiding full re-fetches.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Determines last available date per ticker from `ticker_price_histories`.
 *   • If `--force` is set, re-fetches full 5-year history from config baseline.
 *   • Uses small daily/yearly chunks to safely stay within Polygon rate limits.
 *   • Implements exponential backoff retry on API errors.
 *
 * ⚙️ Options:
 * ----------------------------------------------------------------------------
 *   --ticker=XYZ  → Optional: only process this ticker (case-sensitive).
 *   --force       → Ignore local data and fetch full 5-year history.
 *
 * 🧩 Dependencies:
 * ----------------------------------------------------------------------------
 *   • App\Services\PolygonPriceHistoryService
 *   • DB::table('ticker_price_histories')
 *   • Config keys: polygon.price_history_min_date, polygon.default_timespan
 *
 * 💾 Logging:
 * ----------------------------------------------------------------------------
 *   Logs to channel('polygon'), includes retries, durations, and data counts.
 *
 * 🚀 New in v2.6.4:
 * ----------------------------------------------------------------------------
 *   • Removed forced uppercasing of symbols (`strtoupper()` → preserved case).
 *   • Polygon.io requires exact-case tickers for preferreds, units, and SPACs
 *     (e.g., ABRpD ≠ ABRPD).
 *   • Added detailed inline documentation for case sensitivity handling.
 * ============================================================================
 */
class PolygonTickerPricesIngestIncremental extends Command
{
    protected $signature = 'polygon:ingest-ticker-prices
                            {--ticker= : Specific ticker symbol to ingest (case-sensitive, e.g. ABRpD)}
                            {--force : Reingest full history (default 5 years)}';

    protected $description = 'Incrementally ingest daily ticker prices from Polygon.io with retry and backoff.';

    protected PolygonPriceHistoryService $priceHistoryService;

    public function __construct(PolygonPriceHistoryService $priceHistoryService)
    {
        parent::__construct();
        $this->priceHistoryService = $priceHistoryService;
    }

    public function handle(): int
    {
        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Load Options
        |--------------------------------------------------------------------------
        |
        | ⚠️ Case Sensitivity Note:
        | Polygon’s aggregates endpoint is case-sensitive. Mixed-case tickers like
        | ABRpD or ATHpA must be preserved exactly as stored in the database or
        | supplied by the user.
        |
        | ✅ Fix: Removed strtoupper() normalization.
        */
        $symbol = trim($this->option('ticker') ?? '');  // Case preserved
        $force  = $this->option('force');

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Prepare Ticker Query
        |--------------------------------------------------------------------------
        */
        $query = Ticker::query();
        if ($symbol) {
            $query->where('ticker', $symbol);  // exact match, case-sensitive
        }

        $tickers = $query->get();
        if ($tickers->isEmpty()) {
            $this->warn('⚠️ No tickers found matching criteria.');
            return Command::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Begin Ingestion Loop
        |--------------------------------------------------------------------------
        */
        $this->info("🚀 Starting incremental ingestion for {$tickers->count()} ticker(s)...");
        foreach ($tickers as $ticker) {
            try {
                $this->ingestTicker($ticker, $force);
            } catch (\Throwable $e) {
                Log::channel('polygon')->error("❌ Error ingesting {$ticker->ticker}: {$e->getMessage()}", [
                    'trace' => substr($e->getTraceAsString(), 0, 500),
                ]);
            }
        }

        $this->info('✅ All done.');
        return Command::SUCCESS;
    }

    /**
     * Ingest (or re-ingest) price history for a specific ticker.
     */
    protected function ingestTicker(Ticker $ticker, bool $force = false): void
    {
        $symbol = $ticker->ticker;  // Use exact DB-stored case
        $this->line("→ Processing {$symbol}...");

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Determine Start Date
        |--------------------------------------------------------------------------
        | If not forced, resume from the most recent local date (t + 1 day).
        | Otherwise, fetch the full range starting from configured min_date.
        */
        $latest = null;
        if (! $force) {
            $latest = DB::table('ticker_price_histories')
                ->where('ticker_id', $ticker->id)
                ->where('resolution', '1d')
                ->max('t');
        }

        $minDate = config('polygon.price_history_min_date', '2020-01-01');
        $start = $force || ! $latest
            ? Carbon::parse($minDate)
            : Carbon::parse($latest)->addDay();
        $end = Carbon::now();

        if ($start->gt($end)) {
            $this->line("   ✅ Already up to date.");
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Fetch Data in Yearly Chunks
        |--------------------------------------------------------------------------
        */
        $this->line("   Fetching bars from {$start->toDateString()} → {$end->toDateString()} in yearly chunks...");
        $chunkStart = clone $start;
        $chunkDays = 365;
        $totalBars = 0;

        while ($chunkStart->lte($end)) {
            $chunkEnd = (clone $chunkStart)->addDays($chunkDays - 1)->min($end);
            $this->line("     → {$chunkStart->toDateString()} to {$chunkEnd->toDateString()}");

            $attempt = 0;
            $max = 3;
            $bars = null;

            /*
            |--------------------------------------------------------------------------
            | 3️⃣ Fetch Aggregates with Exponential Backoff
            |--------------------------------------------------------------------------
            */
            while ($attempt < $max) {
                try {
                    $bars = $this->priceHistoryService->fetchAggregates(
                        $symbol,            // case-preserved symbol
                        1,                  // multiplier
                        'day',              // timespan
                        $chunkStart->toDateString(),
                        $chunkEnd->toDateString()
                    );
                    break;
                } catch (\Throwable $e) {
                    $attempt++;
                    $wait = pow(2, $attempt + 1);
                    Log::channel('polygon')->warning("Retry {$attempt}/{$max} for {$symbol}: {$e->getMessage()}");
                    $this->warn("       ⚠️ Retry {$attempt} failed — waiting {$wait}s...");
                    sleep($wait);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 4️⃣ Upsert Retrieved Bars
            |--------------------------------------------------------------------------
            */
            if ($bars) {
                $inserted = $this->priceHistoryService->upsertBars($ticker->id, $symbol, '1d', $bars);
                $totalBars += $inserted;
                $this->line("       ✅ Upserted {$inserted} bars.");
            } else {
                $this->line("       ❌ No data returned for chunk.");
            }

            // Modest sleep between chunk fetches to ease rate limits
            sleep(2);

            // Advance chunk window
            $chunkStart = $chunkEnd->addDay();
        }

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Completion Summary
        |--------------------------------------------------------------------------
        */
        $this->info("   ✅ Completed {$symbol}: {$totalBars} bars inserted/updated.");
        Log::channel('polygon')->info("Incremental ingestion complete", [
            'symbol'   => $symbol,
            'inserted' => $totalBars,
        ]);
    }
}