<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use App\Services\PolygonRealtimePriceService;

/**
 * ============================================================================
 *  polygon:intraday-prices:prefetch   (v1.1.0 — Force Mode + Full Verbosity)
 * ============================================================================
 *
 * Prefetches 1-minute (15-min delayed) intraday OHLCV data from Polygon.io and
 * stores compact snapshots in Redis using PolygonRealtimePriceService.
 *
 * Designed to run every minute via Laravel’s Scheduler, and also used by:
 *   • tickers:refresh-all
 *   • manual warmup during development
 *
 * Features:
 *  • Batch mode (default) — fetch many tickers using --limit
 *  • Single-symbol mode — use --symbol=AAPL
 *  • Force-refresh mode — bypass Redis cache for fresh snapshots
 *  • Active ticker filter (avoids wasted requests)
 *
 * Redis keys created:
 *    tw:rt:snap:{SYMBOL}:{YYYY-MM-DD}
 *
 * Examples:
 *   php artisan polygon:intraday-prices:prefetch
 *   php artisan polygon:intraday-prices:prefetch --limit=1000
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
                            {--symbol= : Only prefetch a single ticker (optional)}
                            {--limit=500 : Number of tickers to process in batch mode}
                            {--force : Force-refresh snapshots (ignore existing Redis cache)}';

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
        $limit  = (int) $this->option('limit', 500);
        $force  = (bool) $this->option('force');

        $logger = Log::channel('ingest');

        $this->info('🚀 Starting intraday prefetch...');
        $this->line('   Mode   : ' . ($symbol ? 'SINGLE' : 'BATCH'));
        $this->line('   Symbol : ' . ($symbol ?: '—'));
        $this->line('   Limit  : ' . $limit);
        $this->line('   Force  : ' . ($force ? 'YES' : 'NO'));
        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | Single-Symbol Mode
        |--------------------------------------------------------------------------
        */
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
            $logger->info("📦 Intraday prefetch (single) complete", [
                'symbol' => $symbol,
                'force'  => $force,
            ]);

            return Command::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Batch Mode — Active Tickers Only
        |--------------------------------------------------------------------------
        */
        $tickers = Ticker::query()
            ->active()
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'ticker']);

        if ($tickers->isEmpty()) {
            $this->warn('⚠️ No active tickers found for batch mode.');
            return Command::SUCCESS;
        }

        $count = $tickers->count();
        $this->info("📡 Prefetching intraday snapshots for {$count} tickers...");
        $this->line("   (active tickers only)");
        $this->newLine();

        $bar = $this->output->createProgressBar($count);
        $bar->setFormat("   🟢 Prefetch: %current%/%max% [%bar%] %percent:3s%%");
        $bar->start();

        foreach ($tickers as $ticker) {
            $realtimeService->getIntradaySnapshotForTicker($ticker, $force);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Prefetched intraday snapshots for {$count} tickers.");

        $logger->info('📦 Intraday prefetch (batch) complete', [
            'count' => $count,
            'limit' => $limit,
            'force' => $force,
        ]);

        return Command::SUCCESS;
    }
}