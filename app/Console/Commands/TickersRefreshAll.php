<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 *  tickers:refresh-all  (v1.0.0 — Umbrella Data Refresh)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Run the core TickerWolf ingestion pipeline in a single, ordered command.
 *   Designed primarily for local/dev "catch me up" runs, but safe for use
 *   in staging as well.
 *
 * Command Signature:
 * ----------------------------------------------------------------------------
 *   php artisan tickers:refresh-all
 *   php artisan tickers:refresh-all --fast
 *
 * Behavior:
 * ----------------------------------------------------------------------------
 *   1. polygon:tickers:ingest           (universe + new symbols)
 *   2. tickers:generate-slugs           (SEO slugs)
 *   3. polygon:ticker-overviews:ingest  (company metadata)
 *   4. polygon:fundamentals:ingest      (recent fundamentals, fast-mode tuned)
 *   5. polygon:ticker-price-histories:ingest
 *        → now auto-uses only missing dates unless --from passed
 *   6. tickers:compute-indicators       (technical indicators)
 *   7. tickers:build-snapshots          (feature snapshots / metrics)
 *   8. polygon:ticker-news:ingest       (news items)
 *   9. polygon:intraday-prices:prefetch (warm Redis intraday snapshot)
 *
 * Notes:
 * ----------------------------------------------------------------------------
 *   • This command assumes a queue worker is running separately:
 *       php artisan queue:work --queue=default --tries=3 --timeout=180
 *   • The --fast flag increases batch sizes & removes sleeps to reduce
 *     runtime on your local machine. Use without --fast for safer production.
 * ============================================================================
 */
class TickersRefreshAll extends Command
{
    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'tickers:refresh-all
                            {--fast : Use aggressive batching and no sleeps for local/dev runs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the full TickerWolf data refresh pipeline in one go.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $fast = (bool) $this->option('fast');
        $logger = Log::channel('ingest');

        $logger->info('🚀 tickers:refresh-all started', ['fast' => $fast]);
        $this->info('🚀 Starting TickerWolf umbrella refresh' . ($fast ? ' (FAST MODE)' : ''));

        // Helper to call and log each step
        $run = function (string $description, string $command) use ($logger) {
            $this->newLine();
            $this->info("▶ {$description}");
            $this->line("   $ {$command}");

            try {
                Artisan::call($command);
                $output = Artisan::output();
                $this->line(trim($output));
                $logger->info("✅ Step completed: {$description}", ['command' => $command]);
            } catch (Throwable $e) {
                $this->error("❌ Step failed: {$description}");
                $this->error($e->getMessage());
                $logger->error("❌ Step failed: {$description}", [
                    'command' => $command,
                    'error'   => $e->getMessage(),
                    'trace'   => substr($e->getTraceAsString(), 0, 400),
                ]);
            }
        };

        // 1️⃣ Base tickers
        $run(
            'Ingest polygon tickers (universe)',
            'polygon:tickers:ingest --market=stocks'
        );

        // 2️⃣ Slugs
        $run(
            'Generate ticker slugs',
            'tickers:generate-slugs'
        );

        // 3️⃣ Overviews
        $overviewCmd = $fast
            ? 'polygon:ticker-overviews:ingest --batch=1500 --sleep=0'
            : 'polygon:ticker-overviews:ingest --batch=1000 --sleep=5';

        $run(
            'Ingest ticker overviews',
            $overviewCmd
        );

        // 4️⃣ Fundamentals (recent-focused for fast mode)
        $fundamentalsCmd = $fast
            ? 'polygon:fundamentals:ingest --timeframe=all --gte=2023-01-01 --batch=3000 --sleep=0'
            : 'polygon:fundamentals:ingest --timeframe=all --gte=2019-01-01 --batch=2000 --sleep=1';

        $run(
            'Ingest fundamentals',
            $fundamentalsCmd
        );

        // 5️⃣ Daily price histories (auto-missing-date logic inside command)
        $priceCmd = $fast
            ? 'polygon:ticker-price-histories:ingest --resolution=1d --sleep=1'
            : 'polygon:ticker-price-histories:ingest --resolution=1d --sleep=1';

        $run(
            'Ingest daily price histories (only missing dates)',
            $priceCmd
        );

        // 6️⃣ Indicators
        $indicatorsCmd = $fast
            ? 'tickers:compute-indicators --from=2019-01-01 --to=2030-01-01 --batch=5000 --sleep=0 --include-inactive'
            : 'tickers:compute-indicators --from=2019-01-01 --to=2030-01-01 --batch=3000 --sleep=1 --include-inactive';

        $run(
            'Compute indicators',
            $indicatorsCmd
        );

        // 7️⃣ Feature snapshots / metrics
        $snapshotsCmd = $fast
            ? 'tickers:build-snapshots --from=2019-01-01 --to=2030-01-01 --batch=3000 --sleep=0 --include-inactive'
            : 'tickers:build-snapshots --from=2019-01-01 --to=2030-01-01 --batch=500 --sleep=1 --include-inactive';

        $run(
            'Build ticker snapshots/metrics',
            $snapshotsCmd
        );

        // 8️⃣ News items
        $newsCmd = $fast
            ? 'polygon:ticker-news:ingest --batch=800 --sleep=0'
            : 'polygon:ticker-news:ingest --batch=500 --sleep=1';

        $run(
            'Ingest ticker news items',
            $newsCmd
        );

        // 9️⃣ Optional: intraday Redis prefetch for today
        $run(
            'Prefetch intraday prices into Redis',
            'polygon:intraday-prices:prefetch --force'
        );

        $this->newLine(2);
        $this->info('🎯 tickers:refresh-all completed. Check logs for any step failures.');
        $logger->info('🏁 tickers:refresh-all finished', ['fast' => $fast]);

        return Command::SUCCESS;
    }
}