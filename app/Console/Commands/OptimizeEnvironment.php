<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 *  system:optimize-environment  (v1.0.0 — Safe Environment Reset Helper)
 * ============================================================================
 *
 * 🔧 Purpose
 * ----------------------------------------------------------------------------
 * Provide a single, well-documented command that safely:
 *
 *   • Clears Laravel caches (config/route/view/application).
 *   • Flushes and prunes queues (jobs + batches) in a controlled way.
 *   • Restarts queue workers so they pick up fresh code/config.
 *
 * This replaces manually running:
 *
 *   php artisan optimize:clear
 *   php artisan cache:clear
 *   php artisan config:clear
 *   php artisan route:clear
 *   php artisan view:clear
 *   php artisan queue:flush
 *   php artisan queue:prune-batches --hours=0
 *   php artisan queue:prune-failed --hours=0
 *   php artisan queue:restart
 *
 * 🧠 Behavior
 * ----------------------------------------------------------------------------
 *  • By default, runs BOTH cache cleanup and queue cleanup.
 *  • You can scope behavior with:
 *
 *      --queues-only   Run ONLY queue-related maintenance.
 *      --no-queues     Run ONLY cache-related maintenance (skip queues).
 *      --force         Skip confirmation prompts (useful for CI / cron).
 *
 *  • Each sub-step is wrapped in a tiny helper that:
 *      - Logs to storage/logs/ingest.log (same as ingestion commands).
 *      - Prints nice console output (✅ / ⚠️ / ❌).
 *      - Catches and logs exceptions without aborting the whole command.
 *
 * 🧪 Example usage
 * ----------------------------------------------------------------------------
 *   # Full environment reset (caches + queues) with confirmation
 *   php artisan system:optimize-environment
 *
 *   # Full reset, no prompts (for scripts / CI)
 *   php artisan system:optimize-environment --force
 *
 *   # Only cache-related resets (no queue flushing)
 *   php artisan system:optimize-environment --no-queues
 *
 *   # Only queue maintenance (leave caches intact)
 *   php artisan system:optimize-environment --queues-only
 *
 * ============================================================================
 */
class OptimizeEnvironment extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'system:optimize-environment
                            {--queues-only : Run only queue maintenance (skip cache clears)}
                            {--no-queues : Run only cache clears (skip queue maintenance)}
                            {--force : Skip interactive confirmation prompts}';

    /**
     * The console command description.
     */
    protected $description = 'Safely clear caches + queues and restart workers for a clean TickerWolf environment.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logger = Log::channel('ingest');

        $queuesOnly = (bool) $this->option('queues-only');
        $noQueues   = (bool) $this->option('no-queues');
        $force      = (bool) $this->option('force');

        // Sanity: prevent conflicting flags.
        if ($queuesOnly && $noQueues) {
            $this->error('❌ You cannot use --queues-only and --no-queues together.');
            return Command::INVALID;
        }

        $this->newLine();
        $this->info('🚿  TickerWolf environment optimization starting...');
        $this->line('   Mode: ' . ($queuesOnly ? 'QUEUES ONLY' : ($noQueues ? 'CACHE ONLY' : 'CACHE + QUEUES')));
        $this->line('   Force: ' . ($force ? 'YES' : 'NO'));
        $this->newLine();

        if (! $force) {
            $confirmed = $this->confirm(
                'This may clear caches and/or flush queues. Do you want to continue?',
                true
            );

            if (! $confirmed) {
                $this->warn('⚠️ Aborted by user. No changes made.');
                return Command::SUCCESS;
            }
        }

        $startedAt = microtime(true);

        /**
         * Small helper to run a sub-command with pretty logging.
         *
         * @param  string       $label   Human-readable description.
         * @param  string       $command The artisan command (e.g. "cache:clear").
         * @param  array<string,mixed> $params Assoc array of params/options.
         */
        $run = function (string $label, string $command, array $params = []) use ($logger) {
            $this->info("▶ {$label}");
            $this->line("   $ php artisan {$command}" . $this->formatParamsInline($params));

            try {
                $exitCode = $this->call($command, $params);

                if ($exitCode === 0) {
                    $this->info("✅ {$label} completed.");
                    $logger->info("✅ {$label}", [
                        'command' => $command,
                        'params'  => $params,
                    ]);
                } else {
                    $this->warn("⚠️ {$label} exited with code {$exitCode}.");
                    $logger->warning("⚠️ {$label} non-zero exit code", [
                        'command'   => $command,
                        'params'    => $params,
                        'exit_code' => $exitCode,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->error("❌ {$label} failed: {$e->getMessage()}");

                $logger->error("❌ {$label} threw an exception", [
                    'command' => $command,
                    'params'  => $params,
                    'error'   => $e->getMessage(),
                    'trace'   => substr($e->getTraceAsString(), 0, 400),
                ]);
            }

            $this->newLine();
        };

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Cache / Config / Route / View Clears
        |--------------------------------------------------------------------------
        |
        | These are safe to run at any time; they simply clear in-memory and
        | on-disk caches so Laravel can regenerate them from your source code.
        |
        | Skipped when --queues-only is used.
        */
        if (! $queuesOnly) {
            $this->sectionHeader('Cache / Config Resets');

            $run('Clear compiled caches', 'optimize:clear');
            $run('Clear application cache', 'cache:clear');
            $run('Clear config cache', 'config:clear');
            $run('Clear route cache', 'route:clear');
            $run('Clear compiled views', 'view:clear');
        }

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Queue Maintenance
        |--------------------------------------------------------------------------
        |
        | Flush, prune batches + failed jobs, then restart workers so they
        | pull fresh code and configuration.
        |
        | Skipped when --no-queues is used.
        */
        if (! $noQueues) {
            $this->sectionHeader('Queue Maintenance');

            // Flush the jobs table (only if you are comfortable dropping ALL pending jobs).
            $run('Flush all pending queue jobs', 'queue:flush');

            // Prune batch metadata immediately (hours=0 means "everything older than now").
            $run('Prune old job batches', 'queue:prune-batches', [
                '--hours' => 0,
            ]);

            // Prune failed jobs (again, hours=0 = everything).
            $run('Prune failed jobs', 'queue:prune-failed', [
                '--hours' => 0,
            ]);

            // Restart any running workers so they reload code/config.
            $run('Restart queue workers', 'queue:restart');
        }

        $elapsed = round(microtime(true) - $startedAt, 2);

        $this->info("🏁 Environment optimization complete in {$elapsed}s.");
        $this->newLine();

        $logger->info('🏁 Environment optimization complete', [
            'mode'    => $queuesOnly ? 'queues-only' : ($noQueues ? 'cache-only' : 'all'),
            'force'   => $force,
            'elapsed' => $elapsed,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Render a small section header for readability.
     */
    protected function sectionHeader(string $title): void
    {
        $this->newLine();
        $this->line(str_repeat('-', 72));
        $this->info(' ' . $title);
        $this->line(str_repeat('-', 72));
        $this->newLine();
    }

    /**
     * Format params for inline echo in "php artisan ..." examples.
     *
     * This is just for pretty console output; it does NOT affect behavior.
     *
     * @param  array<string,mixed>  $params
     * @return string
     */
    protected function formatParamsInline(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        $parts = [];

        foreach ($params as $key => $value) {
            // Options without values (boolean flags)
            if ($value === null || $value === true) {
                $parts[] = "--{$key}";
                continue;
            }

            $parts[] = "--{$key}={$value}";
        }

        return ' ' . implode(' ', $parts);
    }
}