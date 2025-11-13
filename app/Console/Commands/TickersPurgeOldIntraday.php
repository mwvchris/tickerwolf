<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\TickerPriceHistory;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ============================================================================
 *  tickers:purge-old-intraday  (v1.0.0)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Safely purge *intraday* price bars (e.g., 1m, 30m) older than a
 *   configurable retention window, while preserving daily (1d) history.
 *
 * Typical use:
 *   - Keep only the last 7–10 days of intraday bars to avoid unbounded growth.
 *   - Run nightly via Laravel's scheduler after all ingestion is done.
 *
 * Command Signature:
 * ----------------------------------------------------------------------------
 *   php artisan tickers:purge-old-intraday
 *       --days=8
 *
 * Behavior:
 * ----------------------------------------------------------------------------
 *   - Targets rows in ticker_price_histories where:
 *       resolution IN ('1m', '5m', '15m', '30m', '60m')
 *       AND t < now() - {days} days (startOfDay)
 *   - Deletes rows in chunks using chunkById to avoid long-running locks.
 *
 * Safety:
 * ----------------------------------------------------------------------------
 *   - Does NOT touch resolution = '1d' (your main EOD dataset).
 *   - Logs a summary of total rows deleted.
 * ============================================================================
 */
class TickersPurgeOldIntraday extends Command
{
    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'tickers:purge-old-intraday
                            {--days=8 : Retention window in days for intraday bars}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge old intraday price bars (1m/30m/etc) beyond a rolling retention window.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days') ?: 8;
        $cutoff = Carbon::now()->subDays($days)->startOfDay();
        $logger = Log::channel('ingest');

        $this->info("🧹 Purging intraday bars older than {$cutoff->toDateTimeString()} (retention: {$days} days)");

        // Which resolutions are considered "intraday" for purge purposes.
        $intradayResolutions = ['1m', '5m', '15m', '30m', '60m'];

        $totalDeleted = 0;

        try {
            TickerPriceHistory::query()
                ->whereIn('resolution', $intradayResolutions)
                ->where('t', '<', $cutoff)
                ->chunkById(10_000, function ($rows) use (&$totalDeleted) {
                    $ids = $rows->pluck('id')->all();
                    $deleted = TickerPriceHistory::whereIn('id', $ids)->delete();
                    $totalDeleted += $deleted;
                });

            $this->info("✅ Purge complete. Deleted {$totalDeleted} intraday rows.");
            $logger->info('🧹 tickers:purge-old-intraday completed', [
                'days'          => $days,
                'cutoff'        => $cutoff->toIso8601String(),
                'rows_deleted'  => $totalDeleted,
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ Purge failed: ' . $e->getMessage());
            $logger->error('❌ tickers:purge-old-intraday failed', [
                'days'     => $days,
                'cutoff'   => $cutoff->toIso8601String(),
                'error'    => $e->getMessage(),
                'trace'    => substr($e->getTraceAsString(), 0, 400),
            ]);

            return Command::FAILURE;
        }
    }
}