<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * ============================================================================
 *  queue:supervisor  (Hybrid Queue Health Monitor — Option C)
 * ============================================================================
 *
 * 🔧 Purpose
 * ----------------------------------------------------------------------------
 *  Lightweight queue supervisor for TickerWolf. It does NOT replace Docker's
 *  restart policy or `queue:work`, but acts as a hybrid "brain":
 *
 *   • Periodically inspects queue health:
 *       - Queue backlog (default queue size)
 *       - Running job batches
 *       - Failed jobs
 *       - Last `queue:restart` timestamp
 *   • Logs metrics to a dedicated queue supervisor log.
 *   • Automatically calls `queue:restart` when thresholds are exceeded:
 *       - Backlog > QUEUE_BACKLOG_HARD
 *       - OR backlog > QUEUE_BACKLOG_SOFT AND last restart is old
 *
 *  This plays nicely with a dedicated `tickerwolf-queue` Docker service that
 *  runs:
 *
 *    php artisan queue:work \
 *        --queue=default \
 *        --sleep=3 \
 *        --backoff=5 \
 *        --max-jobs=25 \
 *        --max-time=240 \
 *        --tries=3 \
 *        --timeout=120
 *
 *  The supervisor does NOT spawn workers itself; it just orchestrates restarts
 *  and logs insight so you can tune your pipeline safely.
 *
 * 🧪 Example usage
 * ----------------------------------------------------------------------------
 *   # One-off health check
 *   php artisan queue:supervisor
 *
 *   # Dry-run mode (show what would happen, but do nothing)
 *   php artisan queue:supervisor --dry
 *
 *   # Force a restart regardless of thresholds
 *   php artisan queue:supervisor --force-restart
 *
 *  Typically, this command will be scheduled to run every 5 minutes.
 *
 * ============================================================================
 */
class QueueSupervisor extends Command
{
    /**
     * Artisan command signature.
     */
    protected $signature = 'queue:supervisor
                            {--dry : Only log + display metrics, do not mutate anything}
                            {--force-restart : Force queue:restart even if thresholds are not met}';

    /**
     * Human-readable description.
     */
    protected $description = 'Monitor queue health and orchestrate safe worker restarts for TickerWolf.';

    /**
     * Main handler.
     */
    public function handle(): int
    {
        $dryRun       = (bool) $this->option('dry');
        $forceRestart = (bool) $this->option('force-restart');

        $workerConfig = config('queue_workers.default', []);
        $superConfig  = config('queue_workers.supervisor', []);

        $logger = Log::channel('ingest'); // Reuse ingest channel for now; you can make a dedicated one later.

        $connection   = $workerConfig['connection'] ?? config('queue.default');
        $queueName    = $workerConfig['queue'] ?? 'default';

        $backlogSoft  = (int) ($superConfig['backlog_soft'] ?? 1000);
        $backlogHard  = (int) ($superConfig['backlog_hard'] ?? 5000);
        $restartMins  = (int) ($superConfig['restart_minutes'] ?? 60);

        $this->info('🧭 Queue Supervisor (Hybrid Option C)');
        $this->line('   Mode   : ' . ($dryRun ? 'DRY-RUN' : ($forceRestart ? 'FORCE-RESTART' : 'NORMAL')));
        $this->line('   Conn   : ' . $connection);
        $this->line('   Queue  : ' . $queueName);
        $this->line('   Soft   : ' . $backlogSoft . ' jobs');
        $this->line('   Hard   : ' . $backlogHard . ' jobs');
        $this->line('   Restart: ' . $restartMins . ' minutes');
        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Collect Metrics
        |--------------------------------------------------------------------------
        */

        // Approximate backlog size for the target queue.
        $backlog = Queue::connection($connection)->size($queueName);

        // Running job batches (using Laravel's batch system, if used).
        $runningBatches = DB::table('job_batches')
            ->where('status', 'running')
            ->count();

        // Total failed jobs.
        $failedJobs = DB::table('failed_jobs')->count();

        // Last queue:restart time from cache (this is how Laravel implements it).
        $restartKey   = 'illuminate:queue:restart';
        $restartValue = Cache::get($restartKey);
        $lastRestart  = $restartValue ? Carbon::createFromTimestamp($restartValue) : null;
        $minutesSinceRestart = $lastRestart ? $lastRestart->diffInMinutes(now()) : null;

        $this->line("📊 Backlog size     : {$backlog} job(s)");
        $this->line("📊 Running batches  : {$runningBatches}");
        $this->line("📊 Failed jobs      : {$failedJobs}");

        if ($lastRestart) {
            $this->line('📊 Last restart     : ' . $lastRestart->toDateTimeString() . " ({$minutesSinceRestart} min ago)");
        } else {
            $this->line('📊 Last restart     : (no restart marker found)');
        }

        $this->newLine();

        $logger->info('QueueSupervisor metrics', [
            'connection'   => $connection,
            'queue'        => $queueName,
            'backlog'      => $backlog,
            'running'      => $runningBatches,
            'failed'       => $failedJobs,
            'last_restart' => $lastRestart?->toIso8601String(),
            'minutes_since_restart' => $minutesSinceRestart,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Build "Recommended" queue:work command (for logs + docs)
        |--------------------------------------------------------------------------
        */

        $sleep    = $workerConfig['sleep'] ?? 3;
        $backoff  = $workerConfig['backoff'] ?? 5;
        $maxJobs  = $workerConfig['max_jobs'] ?? 25;
        $maxTime  = $workerConfig['max_time'] ?? 240;
        $tries    = $workerConfig['tries'] ?? 3;
        $timeout  = $workerConfig['timeout'] ?? 120;

        $recommendedCommand = sprintf(
            'php artisan queue:work --queue=%s --sleep=%d --backoff=%d --max-jobs=%d --max-time=%d --tries=%d --timeout=%d',
            $queueName,
            $sleep,
            $backoff,
            $maxJobs,
            $maxTime,
            $tries,
            $timeout
        );

        $this->line('🧾 Recommended worker command:');
        $this->line('   ' . $recommendedCommand);
        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Decide Whether to Restart
        |--------------------------------------------------------------------------
        */

        $shouldRestart = false;
        $reason        = null;

        if ($forceRestart) {
            $shouldRestart = true;
            $reason        = 'force flag passed';
        } else {
            if ($backlog >= $backlogHard) {
                $shouldRestart = true;
                $reason        = "backlog {$backlog} >= hard threshold {$backlogHard}";
            } elseif ($backlog >= $backlogSoft && $minutesSinceRestart !== null && $minutesSinceRestart >= $restartMins) {
                $shouldRestart = true;
                $reason        = "backlog {$backlog} >= soft threshold {$backlogSoft} AND last restart {$minutesSinceRestart} min ago >= {$restartMins} min";
            }
        }

        if (! $shouldRestart) {
            $this->info('✅ Queue health within thresholds — no restart needed.');
            return Command::SUCCESS;
        }

        $this->warn("⚠️ Restart recommended: {$reason}");

        if ($dryRun) {
            $this->info('🧪 Dry-run enabled — NOT calling queue:restart.');
            return Command::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Perform Restart via Laravel Mechanism
        |--------------------------------------------------------------------------
        |
        | This sets the `illuminate:queue:restart` cache key, which signals all
        | running workers (in any container) to gracefully exit after finishing
        | their current job. Docker’s restart policy then brings the container
        | back up automatically.
        |
        */

        Artisan::call('queue:restart');

        $this->info('🔁 queue:restart dispatched successfully.');
        $logger->warning('QueueSupervisor triggered queue:restart', [
            'reason'       => $reason,
            'backlog'      => $backlog,
            'running'      => $runningBatches,
            'failed'       => $failedJobs,
            'command'      => $recommendedCommand,
        ]);

        return Command::SUCCESS;
    }
}