<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ============================================================================
 *  tickers:refresh-all  (v4.1 — Orchestrated, Mode-Aware Umbrella Pipeline)
 * ============================================================================
 *
 * 🔧 Purpose
 * ----------------------------------------------------------------------------
 * Run the core TickerWolf ingestion pipeline in a single, ordered command.
 * Primarily for local/dev “catch me up” runs, but safe for staging or even
 * carefully-used production refreshes.
 *
 * This version adds:
 *   • Step orchestration + timing
 *   • Semi-synchronous mode with --wait
 *   • Queue backlog / batch health checks between phases
 *   • Structured summary of which steps succeeded / failed
 *   • Multiple *modes* (full / core / daily / weekly / dev)
 *   • Dev “alpha flag” (--dev) that dominates all other modes and implies --fast
 *   • Optimized Polygon overview batching (streamed + chunked)
 *   • Optimized multi-resolution (1d + 1h) price history engine
 *
 * Command Signature
 * ----------------------------------------------------------------------------
 *   php artisan tickers:refresh-all
 *   php artisan tickers:refresh-all --fast
 *   php artisan tickers:refresh-all --wait
 *   php artisan tickers:refresh-all --dev
 *   php artisan tickers:refresh-all --daily
 *   php artisan tickers:refresh-all --weekly
 *   php artisan tickers:refresh-all --core
 *
 * Flags
 * ----------------------------------------------------------------------------
 *   --fast   → Use more aggressive batching & fewer sleeps for local runs.
 *   --wait   → After EACH major phase, wait for the queue to drain:
 *                - pending jobs on "default" queue reach zero
 *                - job_batches all finished
 *              (with timeouts & warnings, not hard failure)
 *
 *   --dev    → Dev-mode (ALPHA FLAG; dominates all others):
 *                • Implies --fast
 *                • Includes:
 *                    - tickers
 *                    - slugs
 *                    - overviews
 *                    - price histories (1d + 1h, optimized)
 *                    - indicators
 *                    - snapshots
 *                    - intraday prefetch
 *                • Skips:
 *                    - fundamentals
 *                    - news
 *
 *   --daily  → Nightly “daily” pipeline (for production-style runs):
 *                • Includes:
 *                    - tickers
 *                    - slugs
 *                    - overviews
 *                    - price histories (1d + 1h, optimized)
 *                    - indicators
 *                    - snapshots
 *                    - news
 *                    - intraday prefetch
 *                • Skips:
 *                    - fundamentals (handled weekly instead)
 *
 *   --weekly → Heavier weekly pipeline focused on fundamentals:
 *                • Includes:
 *                    - fundamentals
 *                • Skips:
 *                    - all other steps (tickers, slugs, overviews, prices,
 *                      indicators, snapshots, news, intraday)
 *
 *   --core   → Core universe sync only:
 *                • Includes:
 *                    - tickers
 *                    - slugs
 *                    - overviews
 *                • Skips:
 *                    - fundamentals
 *                    - prices
 *                    - indicators
 *                    - snapshots
 *                    - news
 *                    - intraday
 *
 * Precedence
 * ----------------------------------------------------------------------------
 *   1) --dev is ALPHA:
 *        • If present, it *wins* over everything else.
 *        • It implicitly sets --fast = true.
 *        • If combined with other mode flags (--daily, --weekly, --core),
 *          those are ignored with a warning.
 *
 *   2) Other modes (--daily, --weekly, --core):
 *        • Exactly one of these may be used at a time.
 *        • If more than one is passed (and --dev is NOT present), this
 *          command fails fast with an error rather than guess.
 *
 *   3) No mode flags:
 *        • Fall back to “full pipeline” (v3-style behavior):
 *            - tickers
 *            - slugs
 *            - overviews
 *            - fundamentals
 *            - prices (1d + 1h, optimized)
 *            - indicators
 *            - snapshots
 *            - news
 *            - intraday prefetch
 *
 * Behavior (High-Level Pipeline)
 * ----------------------------------------------------------------------------
 *   Steps (in order; actual inclusion controlled by mode):
 *
 *   1. polygon:tickers:ingest           (universe + new symbols)
 *   2. tickers:generate-slugs           (SEO slugs)
 *   3. polygon:ticker-overviews:ingest  (company metadata; streamed + chunked)
 *   4. polygon:fundamentals:ingest      (fundamentals; mode + fast tuned)
 *   5. polygon:ticker-price-histories:ingest
 *        → multi-resolution engine (1d + 1h), missing-date aware, windowed
 *   6. tickers:compute-indicators       (technical indicators)
 *   7. tickers:build-snapshots          (feature snapshots / metrics)
 *   8. polygon:ticker-news:ingest       (news items)
 *   9. polygon:intraday-prices:prefetch (warm Redis intraday snapshot)
 *
 * Notes
 * ----------------------------------------------------------------------------
 *   • Assumes a queue worker is running separately, e.g.:
 *
 *       php artisan queue:work \
 *         --queue=default \
 *         --sleep=3 \
 *         --backoff=5 \
 *         --max-jobs=25 \
 *         --max-time=240 \
 *         --tries=3 \
 *         --timeout=120
 *
 *   • Use --dev for “I’m on my laptop, just catch me up on daily-ish stuff
 *     without crushing my box” (no fundamentals/news).
 *
 *   • Use --daily for the nightly production-style pipeline (everything except
 *     fundamentals).
 *
 *   • Use --weekly for heavier fundamentals-only runs (wired in the scheduler).
 *
 *   • Use --core when you only want the universe / metadata synced.
 * ============================================================================
 */
class TickersRefreshAll extends Command
{
    /**
     * Queue wait settings for --wait mode.
     */
    private const WAIT_POLL_SECONDS = 10;    // How often to poll queue state
    private const WAIT_MAX_MINUTES  = 45;    // Max minutes to wait per phase
    private const WAIT_SOFT_BACKLOG = 50000; // Backlog threshold for “getting large” warning

    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'tickers:refresh-all
                            {--fast : Use aggressive batching and minimal sleeps for local/dev runs}
                            {--wait : Block between phases until queues have drained (semi-synchronous mode)}
                            {--dev : Dev-mode (alpha flag; implies --fast; skips fundamentals/news)}
                            {--daily : Daily-mode pipeline (nightly; skips fundamentals)}
                            {--weekly : Weekly-mode pipeline (fundamentals only)}
                            {--core : Core universe sync only (tickers/slugs/overviews)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the TickerWolf data refresh pipeline (full or mode-based) in one go.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $fast   = (bool) $this->option('fast');
        $wait   = (bool) $this->option('wait');
        $dev    = (bool) $this->option('dev');
        $daily  = (bool) $this->option('daily');
        $weekly = (bool) $this->option('weekly');
        $core   = (bool) $this->option('core');

        $logger    = Log::channel('ingest');
        $startedAt = microtime(true);

        /*
        |--------------------------------------------------------------------------
        | Mode Resolution / Precedence
        |--------------------------------------------------------------------------
        | --dev is alpha (dominates and implies --fast).
        | Other modes must be mutually exclusive.
        */
        $mode = 'full'; // default

        if ($dev) {
            $mode = 'dev';
            $fast = true;

            if ($daily || $weekly || $core) {
                $this->warn('⚠️ --dev provided; ignoring --daily / --weekly / --core (dev is alpha).');
                $logger->warning('Mode conflict resolved in favor of dev', [
                    'daily'  => $daily,
                    'weekly' => $weekly,
                    'core'   => $core,
                ]);
            }
        } else {
            $modeFlagsCount = ($daily ? 1 : 0) + ($weekly ? 1 : 0) + ($core ? 1 : 0);

            if ($modeFlagsCount > 1) {
                $this->error('❌ You may specify at most ONE of: --daily, --weekly, --core (or use --dev).');
                $logger->error('Invalid mode combination', [
                    'daily'  => $daily,
                    'weekly' => $weekly,
                    'core'   => $core,
                ]);

                return self::FAILURE;
            }

            if ($daily) {
                $mode = 'daily';
            } elseif ($weekly) {
                $mode = 'weekly';
            } elseif ($core) {
                $mode = 'core';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Step Inclusion Matrix per Mode
        |--------------------------------------------------------------------------
        */
        $includeTickers       = true;
        $includeSlugs         = true;
        $includeOverviews     = true;
        $includeFundamentals  = true;
        $includePrices        = true;
        $includeIndicators    = true;
        $includeSnapshots     = true;
        $includeNews          = true;
        $includeIntraday      = true;

        switch ($mode) {
            case 'dev':
                // Dev: everything except fundamentals + news.
                $includeFundamentals = false;
                $includeNews         = false;
                break;

            case 'daily':
                // Daily: full nightly pipeline except fundamentals (handled weekly).
                $includeFundamentals = false;
                // Everything else remains enabled.
                break;

            case 'weekly':
                // Weekly: fundamentals-only.
                $includeTickers      = false;
                $includeSlugs        = false;
                $includeOverviews    = false;
                $includePrices       = false;
                $includeIndicators   = false;
                $includeSnapshots    = false;
                $includeNews         = false;
                $includeIntraday     = false;
                break;

            case 'core':
                // Core: universe + metadata only.
                $includeFundamentals = false;
                $includePrices       = false;
                $includeIndicators   = false;
                $includeSnapshots    = false;
                $includeNews         = false;
                $includeIntraday     = false;
                break;

            case 'full':
            default:
                // Full: everything enabled.
                break;
        }

        /*
        |--------------------------------------------------------------------------
        | Header Logging
        |--------------------------------------------------------------------------
        */
        $logger->info('🚀 tickers:refresh-all started', [
            'fast' => $fast,
            'wait' => $wait,
            'mode' => $mode,
        ]);

        $this->newLine();
        $this->info('🚀 Starting TickerWolf umbrella refresh');
        $this->line('   Mode            : ' . $mode . ($dev ? ' (dev is alpha)' : ''));
        $this->line('   Fast batching   : ' . ($fast ? 'YES' : 'no'));
        $this->line('   Queue-aware     : ' . ($wait ? 'YES (--wait enabled)' : 'no'));
        $this->line('   Queue connection: database');
        $this->line('   Queue name      : default');
        $this->newLine();

        // Structured results store for summary at the end.
        $results = [];

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Base tickers
        |--------------------------------------------------------------------------
        */
        if ($includeTickers) {
            $this->runStep(
                key: 'tickers',
                label: 'Ingest polygon tickers (universe)',
                command: 'polygon:tickers:ingest --market=stocks',
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['tickers'] = [
                'label'    => 'Ingest polygon tickers (universe)',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Slugs
        |--------------------------------------------------------------------------
        */
        if ($includeSlugs) {
            $this->runStep(
                key: 'slugs',
                label: 'Generate ticker slugs',
                command: 'tickers:generate-slugs',
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['slugs'] = [
                'label'    => 'Generate ticker slugs',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Overviews (optimized streamed batching)
        |--------------------------------------------------------------------------
        */
        if ($includeOverviews) {
            // New optimized command signature:
            // polygon:ticker-overviews:ingest --limit=0 --batch=... --per-job=... --sleep=...
            $overviewCmd = $fast
                ? 'polygon:ticker-overviews:ingest --limit=0 --batch=1500 --per-job=75 --sleep=0'
                : 'polygon:ticker-overviews:ingest --limit=0 --batch=800 --per-job=50 --sleep=1';

            $this->runStep(
                key: 'overviews',
                label: 'Ingest ticker overviews',
                command: $overviewCmd,
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['overviews'] = [
                'label'    => 'Ingest ticker overviews',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Fundamentals (mode + fast tuned)
        |--------------------------------------------------------------------------
        |
        | NOTE: For BOTH fast + non-fast, we now use gte=2019-01-01 so that dev
        | and full runs have a consistent historical floor.
        */
        if ($includeFundamentals) {
            $fundamentalsCmd = $fast
                ? 'polygon:fundamentals:ingest --timeframe=all --gte=2019-01-01 --batch=3000 --sleep=0'
                : 'polygon:fundamentals:ingest --timeframe=all --gte=2019-01-01 --batch=1500 --sleep=1';

            $this->runStep(
                key: 'fundamentals',
                label: 'Ingest fundamentals',
                command: $fundamentalsCmd,
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['fundamentals'] = [
                'label'    => 'Ingest fundamentals',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Multi-resolution price histories (1d + 1h, optimized engine)
        |--------------------------------------------------------------------------
        |
        | Internals of polygon:ticker-price-histories:ingest now:
        |   • perform per-ticker missing-date detection (auto-from)
        |   • ingest both 1d and 1h bars per ticker
        |   • window the date ranges to keep per-ticker Polygon calls bounded
        |   • support redundancy days to heal gaps
        */
        if ($includePrices) {
            $priceCmd = $fast
                ? 'polygon:ticker-price-histories:ingest --window=45 --sleep=0 --redundancy-days=2'
                : 'polygon:ticker-price-histories:ingest --window=14 --sleep=2 --redundancy-days=3';

            $this->runStep(
                key: 'prices',
                label: 'Ingest price histories (1d + 1h, optimized multi-resolution)',
                command: $priceCmd,
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['prices'] = [
                'label'    => 'Ingest price histories (1d + 1h, optimized multi-resolution)',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 6️⃣ Indicators
        |--------------------------------------------------------------------------
        */
        if ($includeIndicators) {
            $indicatorsCmd = $fast
                ? 'tickers:compute-indicators --from=2019-01-01 --to=2030-01-01 --batch=5000 --sleep=0 --include-inactive'
                : 'tickers:compute-indicators --from=2019-01-01 --to=2030-01-01 --batch=2000 --sleep=1 --include-inactive';

            $this->runStep(
                key: 'indicators',
                label: 'Compute indicators',
                command: $indicatorsCmd,
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['indicators'] = [
                'label'    => 'Compute indicators',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 7️⃣ Feature snapshots / metrics
        |--------------------------------------------------------------------------
        */
        if ($includeSnapshots) {
            $snapshotsCmd = $fast
                ? 'tickers:build-snapshots --from=2019-01-01 --to=2030-01-01 --batch=3000 --sleep=0 --include-inactive'
                : 'tickers:build-snapshots --from=2019-01-01 --to=2030-01-01 --batch=1000 --sleep=1 --include-inactive';

            $this->runStep(
                key: 'snapshots',
                label: 'Build ticker snapshots/metrics',
                command: $snapshotsCmd,
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['snapshots'] = [
                'label'    => 'Build ticker snapshots/metrics',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 8️⃣ News items
        |--------------------------------------------------------------------------
        |
        | Internals handle:
        |   • recent-window based ingestion (e.g., last N days)
        |   • pagination / multiple pages per ticker when needed
        */
        if ($includeNews) {
            $newsCmd = $fast
                ? 'polygon:ticker-news:ingest --batch=800 --sleep=0'
                : 'polygon:ticker-news:ingest --batch=400 --sleep=1';

            $this->runStep(
                key: 'news',
                label: 'Ingest ticker news items',
                command: $newsCmd,
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['news'] = [
                'label'    => 'Ingest ticker news items',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 9️⃣ Optional: intraday Redis prefetch for today
        |--------------------------------------------------------------------------
        |
        | This is intentionally left as a short, synchronous prefetch that hits a
        | limited universe (e.g., top-N active tickers per our command logic).
        |
        | Note: you already have a separate schedule for intraday prefetch
        | running every minute; this step is just a one-shot warm-up at the end.
        */
        if ($includeIntraday) {
            $this->runStep(
                key: 'intraday',
                label: 'Prefetch intraday prices into Redis',
                command: 'polygon:intraday-prices:prefetch --force',
                wait: $wait,
                logger: $logger,
                results: $results
            );
        } else {
            $results['intraday'] = [
                'label'    => 'Prefetch intraday prices into Redis',
                'command'  => '(skipped by mode)',
                'success'  => true,
                'duration' => null,
                'error'    => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 🔚 Final Summary
        |--------------------------------------------------------------------------
        */
        $totalSeconds = microtime(true) - $startedAt;
        $this->newLine(2);

        $this->info('📊 TickerWolf umbrella pipeline summary');
        $this->line(str_repeat('─', 72));

        $anyFailures = false;

        foreach ($results as $key => $step) {
            $statusLabel = $step['success'] ? '✅' : '❌';
            $anyFailures = $anyFailures || ! $step['success'];

            $timeStr = $step['duration'] !== null
                ? sprintf('%5.1fs', $step['duration'])
                : '  n/a ';

            $this->line(sprintf(
                "%s %-18s  %s  (%s)",
                $statusLabel,
                '[' . $key . ']',
                $step['label'],
                $timeStr
            ));

            if (! $step['success'] && $step['error']) {
                $this->line('   ↳ ' . $step['error']);
            }
        }

        $this->line(str_repeat('─', 72));
        $this->line(sprintf(
            "⏱  Total wall-clock time: %.1f seconds (~%.1f minutes)",
            $totalSeconds,
            $totalSeconds / 60
        ));

        // Final queue snapshot for --wait mode.
        $pendingJobs = $this->safeCount(function () {
            return DB::table('jobs')->where('queue', 'default')->count();
        });

        $runningBatches = $this->safeCount(function () {
            return DB::table('job_batches')->whereNull('finished_at')->count();
        });

        $failedJobs = $this->safeCount(function () {
            return DB::table('failed_jobs')->count();
        });

        $this->newLine();
        $this->info('📦 Final queue snapshot (database / default):');
        $this->line('   Pending jobs      : ' . ($pendingJobs ?? 'n/a'));
        $this->line('   Running batches   : ' . ($runningBatches ?? 'n/a'));
        $this->line('   Failed jobs (all) : ' . ($failedJobs ?? 'n/a'));

        if ($failedJobs > 0) {
            $this->warn('⚠️ There are failed jobs in the queue. Inspect via:');
            $this->line('   php artisan queue:failed');
        }

        if ($anyFailures) {
            $this->newLine();
            $this->error('❌ tickers:refresh-all completed WITH step failures. See logs for details.');
        } else {
            $this->newLine();
            $this->info('🎯 tickers:refresh-all completed successfully. Check logs for per-step details.');
        }

        $logger->info('🏁 tickers:refresh-all finished', [
            'fast'     => $fast,
            'wait'     => $wait,
            'mode'     => $mode,
            'duration' => $totalSeconds,
            'failures' => $anyFailures,
            'queue'    => [
                'pending' => $pendingJobs,
                'running' => $runningBatches,
                'failed'  => $failedJobs,
            ],
        ]);

        return $anyFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run a single pipeline step (sub-command) with logging, timing,
     * error capture, and optional --wait queue drainage.
     *
     * @param  array<string,mixed>  $results
     */
    protected function runStep(
        string $key,
        string $label,
        string $command,
        bool $wait,
        $logger,
        array &$results
    ): void {
        $this->newLine();
        $this->info("▶ {$label}");
        $this->line("   $ {$command}");

        $stepStart = microtime(true);
        $success   = false;
        $errorMsg  = null;

        try {
            $exitCode = Artisan::call($command);
            $output   = trim(Artisan::output());

            if ($output !== '') {
                // Indent output so it visually nests under the step.
                foreach (explode(PHP_EOL, $output) as $line) {
                    $this->line('   ' . $line);
                }
            }

            $success = ($exitCode === 0);

            if (! $success) {
                $errorMsg = "Artisan exit code {$exitCode}";
                $this->error("❌ Step failed (non-zero exit code): {$label}");
                $logger->error("❌ Step failed (non-zero exit code): {$label}", [
                    'command'  => $command,
                    'exitCode' => $exitCode,
                ]);
            } else {
                $logger->info("✅ Step completed: {$label}", [
                    'command'  => $command,
                    'exitCode' => $exitCode,
                ]);
            }
        } catch (Throwable $e) {
            $success  = false;
            $errorMsg = $e->getMessage();

            $this->error("❌ Step failed: {$label}");
            $this->error($e->getMessage());

            $logger->error("❌ Exception during step: {$label}", [
                'command' => $command,
                'error'   => $e->getMessage(),
                'trace'   => substr($e->getTraceAsString(), 0, 400),
            ]);
        }

        $duration = microtime(true) - $stepStart;

        // Record result for final summary.
        $results[$key] = [
            'label'    => $label,
            'command'  => $command,
            'success'  => $success,
            'duration' => $duration,
            'error'    => $errorMsg,
        ];

        // If step failed, we *continue* with later stages, but mark failures in summary.
        if (! $success) {
            return;
        }

        // In --wait mode, block until queue is reasonably drained after this stage.
        if ($wait) {
            $this->waitForQueueDrain($label, $logger);
        }
    }

    /**
     * In --wait mode, block until:
     *   • pending jobs on "default" queue reach zero, AND
     *   • all job_batches are finished,
     * OR until timeout is reached.
     */
    protected function waitForQueueDrain(string $afterStep, $logger): void
    {
        $this->newLine();
        $this->info("⏳ Waiting for queue to drain after step: {$afterStep}");
        $this->line(sprintf(
            '   Polling every %ds, timeout after %d minutes...',
            self::WAIT_POLL_SECONDS,
            self::WAIT_MAX_MINUTES
        ));

        $started          = Carbon::now();
        $warnedOnBacklog  = false;

        while (true) {
            try {
                $pending = DB::table('jobs')
                    ->where('queue', 'default')
                    ->count();

                $runningBatches = DB::table('job_batches')
                    ->whereNull('finished_at')
                    ->count();

                $failed = DB::table('failed_jobs')->count();
            } catch (Throwable $e) {
                $this->warn('⚠️ Unable to query queue tables while waiting; skipping wait.');
                $logger->warning('⚠️ Queue wait aborted due to DB error', [
                    'after_step' => $afterStep,
                    'error'      => $e->getMessage(),
                ]);
                return;
            }

            $this->line(sprintf(
                '   → Pending: %d, Running batches: %d, Failed: %d',
                $pending,
                $runningBatches,
                $failed
            ));

            if ($pending === 0 && $runningBatches === 0) {
                $this->info('✅ Queue drained; proceeding to next step.');
                $logger->info('✅ Queue drained after step', [
                    'after_step' => $afterStep,
                ]);
                return;
            }

            // Soft warning if backlog gets large.
            if (! $warnedOnBacklog && $pending > self::WAIT_SOFT_BACKLOG) {
                $warnedOnBacklog = true;
                $this->warn("⚠️ Large backlog detected ({$pending} jobs). Make sure queue:work is running.");
                $logger->warning('⚠️ Large backlog detected during waitForQueueDrain', [
                    'after_step' => $afterStep,
                    'pending'    => $pending,
                ]);
            }

            // Timeout?
            if ($started->diffInMinutes(now()) >= self::WAIT_MAX_MINUTES) {
                $this->warn('⚠️ Queue did not fully drain before timeout; continuing anyway.');
                $logger->warning('⚠️ waitForQueueDrain timeout reached', [
                    'after_step' => $afterStep,
                    'pending'    => $pending,
                    'running'    => $runningBatches,
                ]);
                return;
            }

            sleep(self::WAIT_POLL_SECONDS);
        }
    }

    /**
     * Small helper to safely run a counting closure; if anything explodes,
     * return null instead of throwing.
     */
    protected function safeCount(callable $callback): ?int
    {
        try {
            return (int) $callback();
        } catch (Throwable) {
            return null;
        }
    }
}