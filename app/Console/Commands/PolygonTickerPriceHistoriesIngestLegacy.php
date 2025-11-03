<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Services\PolygonTickerPriceHistoryService;
use Carbon\Carbon;

/**
 * ============================================================================
 *  polygon:ticker-price-histories:ingest-legacy  (v2.6.4 — Symbol Case Fix)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Performs direct (non-queued) ingestion of Polygon ticker price histories.
 *   Useful for small debug runs or isolated re-ingestion without the queue system.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Fetches Polygon aggregates directly for each ticker in sequence.
 *   • Supports both full-range and single-symbol operation (--symbol=XYZ).
 *   • Pulls defaults (min_date, resolution, multiplier) from config/polygon.php.
 *   • Writes detailed progress and timing logs to the 'ingest' log channel.
 *
 * 🧩 Key Parameters:
 * ----------------------------------------------------------------------------
 *   --symbol=XYZ      → Optional: ingest only a specific ticker.
 *   --resolution=1d   → Aggregation resolution (1d, 1m, etc.).
 *   --from / --to     → Custom start/end date, defaults from config.
 *   --batch / --sleep → Control throughput when processing many tickers.
 *
 * 💾 Logging:
 * ----------------------------------------------------------------------------
 *   Logs are written to storage/logs/ingest.log and include per-ticker status,
 *   error details, retry counts, and completion summaries.
 *
 * 🚀 New in v2.6.4:
 * ----------------------------------------------------------------------------
 *   • Removed forced uppercase normalization for ticker symbols.
 *   • Polygon’s API is case-sensitive for preferreds, SPACs, and units.
 *     (e.g., ABRpD ≠ ABRPD)
 *   • Preserves exact case as stored in the database or supplied via CLI.
 *   • Clarified symbol handling comments for maintainability.
 * ============================================================================
 */
class PolygonTickerPriceHistoriesIngestLegacy extends Command
{
    protected $signature = 'polygon:ticker-price-histories:ingest-legacy
                            {--symbol= : Single ticker symbol to ingest (case-sensitive, e.g. ABRpD)}
                            {--resolution=1d : Resolution (1d, 1m, 5m, etc.)}
                            {--from=2020-01-01 : Start date (YYYY-MM-DD)}
                            {--to=null : End date (YYYY-MM-DD) or null for today}
                            {--multiplier=1 : Multiplier for Polygon aggregates endpoint}
                            {--limit=0 : Limit total tickers processed}
                            {--batch=1000 : Number of tickers per chunk}
                            {--sleep=15 : Seconds to sleep between batches}';

    protected $description = 'LEGACY — Direct (non-queued) ingestion of Polygon ticker price histories.';

    public function handle(): int
    {
        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Load Options & Config Defaults
        |--------------------------------------------------------------------------
        |
        | ⚠️  Case Sensitivity Note:
        | Polygon.io requires exact-case ticker symbols for many instruments.
        | Prior builds converted all tickers to uppercase, which caused data
        | fetch failures for tickers such as ABRpD, ATHpA, etc.
        |
        | ✅ Fix: Preserve the provided case (do not call strtoupper()).
        */
        $symbol     = trim($this->option('symbol') ?? '');  // ✅ Case preserved
        $resolution = $this->option('resolution') ?? config('polygon.default_timespan', '1d');
        $multiplier = (int) ($this->option('multiplier') ?? config('polygon.default_multiplier', 1));
        $from       = $this->option('from') ?? config('polygon.price_history_min_date', '2020-01-01');

        $toOption   = $this->option('to');
        $to         = ($toOption === 'null' || $toOption === null)
            ? now()->toDateString()
            : $toOption;

        $limit      = (int) $this->option('limit');
        $batchSize  = (int) $this->option('batch');
        $sleep      = (int) $this->option('sleep');

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Initialize Logging & Diagnostics
        |--------------------------------------------------------------------------
        */
        $logger = Log::channel('ingest');
        $logger->info("🚀 [LEGACY] Starting Polygon ingestion", [
            'symbol'     => $symbol ?: 'ALL',
            'resolution' => $resolution,
            'multiplier' => $multiplier,
            'from'       => $from,
            'to'         => $to,
            'limit'      => $limit,
            'batch'      => $batchSize,
            'sleep'      => $sleep,
        ]);

        $this->info("📈 [LEGACY] Polygon price history ingestion (direct mode)...");
        $this->line("   Symbol     : " . ($symbol ?: 'ALL TICKERS'));
        $this->line("   Range      : {$from} → {$to}");
        $this->line("   Resolution : {$resolution}");
        $this->line("   Multiplier : {$multiplier}");
        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Build Ticker Query
        |--------------------------------------------------------------------------
        |
        |  Preserve case sensitivity in the WHERE clause.
        |  Many tickers (esp. preferred shares) have mixed-case symbols.
        */
        $query = Ticker::orderBy('id')->select('id', 'ticker');

        if ($symbol) {
            $query->where('ticker', $symbol);  // ✅ exact match (case-sensitive)
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->warn('⚠️ No tickers found to ingest.');
            return Command::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Initialize Service & Progress Bar
        |--------------------------------------------------------------------------
        */
        $this->info("Processing {$total} ticker(s) in chunks of {$batchSize}...");
        $service = app(PolygonTickerPriceHistoryService::class);

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat("   🟢 Progress: %current%/%max% [%bar%] %percent:3s%%");
        $bar->start();
        $chunkIndex = 0;

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Process Ticketers in Batches (Direct, No Queue)
        |--------------------------------------------------------------------------
        */
        $query->chunk($batchSize, function ($tickers) use (
            $service, $resolution, $multiplier, $from, $to, $sleep, &$chunkIndex, $bar, $logger
        ) {
            $chunkIndex++;
            $logger->info("🔹 Processing chunk #{$chunkIndex} (" . count($tickers) . " tickers)");

            foreach ($tickers as $ticker) {
                try {
                    // Directly invoke the PolygonTickerPriceHistoryService
                    $service->fetchAndStore($ticker, $from, $to, $resolution, $multiplier);
                    $bar->advance();
                    $this->line("   ✅ {$ticker->ticker} complete.");
                } catch (\Throwable $e) {
                    $logger->error("❌ Error ingesting {$ticker->ticker}: {$e->getMessage()}", [
                        'ticker_id' => $ticker->id,
                        'trace' => substr($e->getTraceAsString(), 0, 500),
                    ]);
                    $this->error("   ❌ Failed {$ticker->ticker}: {$e->getMessage()}");
                }
            }

            if ($sleep > 0) {
                $this->newLine();
                $this->info("⏳ Sleeping {$sleep}s before next batch...");
                sleep($sleep);
            }
        });

        /*
        |--------------------------------------------------------------------------
        | 6️⃣ Finalization & Summary
        |--------------------------------------------------------------------------
        */
        $bar->finish();
        $this->newLine(2);
        $this->info("🎯 [LEGACY] Completed all {$chunkIndex} batches.");
        $logger->info('[LEGACY] Polygon ingestion complete', ['batches' => $chunkIndex]);

        return Command::SUCCESS;
    }
}