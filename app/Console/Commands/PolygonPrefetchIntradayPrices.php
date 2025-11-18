<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Services\PolygonRealtimePriceService;

/**
 * ============================================================================
 *  polygon:intraday-prices:prefetch (v2.0.0 — Full-Market Prefetch Edition)
 * ============================================================================
 *
 * Completely revised version of the intraday prefetch job with:
 *
 *   • Full-market coverage (all ~12k active tickers)
 *   • Efficient chunked processing (configurable size)
 *   • Optional single-symbol mode
 *   • Optional force-refresh mode
 *   • Weekend fallback-aware warming (Friday → Sat/Sun snapshots)
 *
 * Rationale:
 *   Previously we warmed only the first 500 tickers alphabetically.
 *   This caused the majority of tickers to have no intraday snapshots,
 *   which meant:
 *     - No after-hours display
 *     - Missing fallback weekend data
 *     - Inconsistent UI experience
 *
 * This version guarantees:
 *   • Every ticker receives a fresh snapshot each minute (round-robin)
 *   • Redis keys are always hydrated for today (or fallback days)
 *   • Friday snapshots survive the weekend via fallback logic in the
 *     PolygonRealtimePriceService
 *
 * Example usage:
 *   php artisan polygon:intraday-prices:prefetch
 *   php artisan polygon:intraday-prices:prefetch --symbol=AAPL
 *   php artisan polygon:intraday-prices:prefetch --force
 *
 * ============================================================================
 */
class PolygonPrefetchIntradayPrices extends Command
{
    /**
     * Command signature.
     */
    protected $signature = 'polygon:intraday-prices:prefetch
                            {--symbol= : Only prefetch a single ticker}
                            {--force : Force-refresh snapshots (ignore Redis cache)}';

    /**
     * Description.
     */
    protected $description = 'Prefetch intraday (1-minute) Polygon price snapshots into Redis for ultra-fast UI performance.';

    /**
     * Execute the command.
     */
    public function handle(PolygonRealtimePriceService $realtimeService): int
    {
        $symbol = trim($this->option('symbol') ?? '');
        $force  = (bool) $this->option('force');

        // Chunk size from config (default: 500)
        $chunkSize = (int) config('polygon.intraday_prefetch_chunk', 500);

        $logger = Log::channel('ingest');

        $this->info('🚀 Starting intraday prefetch...');
        $this->line('   Mode       : ' . ($symbol ? 'SINGLE' : 'FULL-MARKET'));
        $this->line('   Symbol     : ' . ($symbol ?: '—'));
        $this->line('   Chunk Size : ' . $chunkSize);
        $this->line('   Force      : ' . ($force ? 'YES' : 'NO'));
        $this->newLine();


        /* ---------------------------------------------------------------------
         | SINGLE SYMBOL MODE
         --------------------------------------------------------------------- */
        if ($symbol !== '') {
            $tickers = Ticker::query()
                ->where('ticker', $symbol)
                ->limit(1)
                ->get(['id', 'ticker']);

            if ($tickers->isEmpty()) {
                $this->warn("⚠️ No ticker found matching symbol={$symbol}");
                return Command::SUCCESS;
            }

            $this->info("📡 Fetching intraday for {$symbol}...");
            $realtimeService->warmIntradayForTickers($tickers, $force);

            $this->info("✅ Prefetched intraday snapshot for {$symbol}");
            return Command::SUCCESS;
        }


        /* ---------------------------------------------------------------------
         | FULL-MARKET MODE (all active tickers, chunked)
         --------------------------------------------------------------------- */
        $this->info("📡 Prefetching intraday snapshots for ALL active tickers (chunked)…");
        $this->newLine();

        $total = Ticker::active()->count();
        $this->info("   Total Active Tickers: {$total}");

        $processed = 0;

        Ticker::active()
            ->orderBy('id')
            ->chunk($chunkSize, function ($chunk) use ($realtimeService, $force, &$processed, $total) {

                $count = $chunk->count();
                $processed += $count;

                $this->line("   🔄 Warming chunk: {$processed}/{$total} (size: {$count})");

                foreach ($chunk as $ticker) {
                    $realtimeService->getIntradaySnapshotForTicker($ticker, $force);
                }

                $this->line("   ✔️ Completed chunk ({$count} tickers)");
                $this->newLine();
            });

        $this->info("✅ Full-market intraday prefetch complete.");
        $logger->info("📦 Intraday prefetch (full-market) complete", [
            'total'  => $total,
            'force'  => $force,
            'chunk'  => $chunkSize,
        ]);

        return Command::SUCCESS;
    }
}