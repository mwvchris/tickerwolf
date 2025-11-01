<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use App\Jobs\ComputeTickerIndicatorsJob;

/**
 * ============================================================================
 *  tickers:backfill-indicators
 * ============================================================================
 * Finds tickers missing any of the core DB-stored indicators (macd, atr, adx, vwap)
 * and optionally queues ComputeTickerIndicatorsJob for backfilling.
 * ============================================================================
 */
class TickersBackfillIndicatorsCommand extends Command
{
    protected $signature = 'tickers:backfill-indicators {--dry-run : Only show missing tickers without dispatching}';
    protected $description = 'Detect and optionally backfill missing core indicators';

    public function handle(): int
    {
        $coreIndicators = Config::get('indicators.storage.ticker_indicators', []);
        $this->info("🧭 Checking indicator coverage for: " . implode(', ', $coreIndicators));

        $tickers = DB::table('tickers')->pluck('id')->toArray();
        $missingMap = [];

        foreach ($coreIndicators as $indicator) {
            $have = DB::table('ticker_indicators')
                ->where('indicator', $indicator)
                ->distinct()
                ->pluck('ticker_id')
                ->toArray();

            $missing = array_diff($tickers, $have);
            $missingMap[$indicator] = $missing;

            $this->line(sprintf("%-8s missing for %d tickers", $indicator, count($missing)));
        }

        if ($this->option('dry-run')) {
            $this->info("✅ Dry run only. No jobs dispatched.");
            return Command::SUCCESS;
        }

        // Flatten missing ticker IDs across all indicators
        $toBackfill = collect($missingMap)->flatten()->unique()->values()->all();

        if (empty($toBackfill)) {
            $this->info("🎉 All indicators are up to date — no backfill required.");
            return Command::SUCCESS;
        }

        $this->info("🚀 Dispatching backfill jobs for " . count($toBackfill) . " tickers…");

        foreach (array_chunk($toBackfill, 50) as $chunk) {
            // ✅ FIXED: Pass both tickers and indicator list
            dispatch(new ComputeTickerIndicatorsJob($chunk, $coreIndicators))
                ->onQueue('default');
        }

        Log::channel('ingest')->info("🧮 Backfill jobs dispatched", [
            'tickers' => count($toBackfill),
            'indicators' => $coreIndicators,
        ]);

        $this->info("✅ Dispatched backfill jobs for " . count($toBackfill) . " tickers.");
        return Command::SUCCESS;
    }
}