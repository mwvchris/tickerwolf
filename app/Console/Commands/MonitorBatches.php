<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorBatches extends Command
{
    protected $signature = 'monitor:batches {--refresh=5 : Refresh interval in seconds} {--stale=2 : Days before batch is considered stale}';
    protected $description = 'Monitor Laravel job batches in real time (with colorized output)';

    public function handle(): int
    {
        $refresh = (int) $this->option('refresh');
        $staleDays = (int) $this->option('stale');

        $this->info("Monitoring batches (refresh: {$refresh}s, stale: {$staleDays} days)");
        $this->info('Press D = delete stale, A = delete all, Q = quit.');
        $this->newLine();

        while (true) {
            $this->displayBatches();
            $this->listenForKey($staleDays);
            sleep($refresh);
        }

        return 0;
    }

    /**
     * Display the current batch statuses.
     */
    protected function displayBatches(): void
    {
        $batches = DB::table('job_batches')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $this->clearScreen();

        $this->line(now()->format('Y-m-d H:i:s'));
        $this->info('📊 Monitoring recent job batches (showing ' . $batches->count() . ')');
        $this->newLine();

        foreach ($batches as $batch) {
            $pending = max(0, $batch->pending_jobs);
            $total   = max(0, $batch->total_jobs);
            $failed  = max(0, $batch->failed_jobs);

            $progress = $total > 0
                ? round((($total - $pending) / $total) * 100, 1)
                : 0;

            $barLength = 40;
            $filled = (int) round(($progress / 100) * $barLength);
            $bar = str_repeat('█', $filled) . str_repeat('░', max(0, $barLength - $filled));

            $status = match (true) {
                $batch->cancelled_at !== null => "<fg=yellow>Cancelled</>",
                $batch->finished_at !== null  => "<fg=green>Finished</>",
                $failed > 0                   => "<fg=red>Partial Fail</>",
                default                       => "<fg=cyan>Running</>",
            };

            $started = Carbon::parse($batch->created_at)->diffForHumans();
            $finished = $batch->finished_at
                ? Carbon::parse($batch->finished_at)->diffForHumans()
                : '—';

            $this->line(" <options=bold>{$batch->name}</>  ID: <fg=gray>{$batch->id}</>");
            $this->line(" ├─ Total: {$total}   Pending: {$pending}   Failed: {$failed}");
            $this->line(" ├─ Status: {$status}   Progress: {$progress}%");
            $this->line(" │  {$bar}");
            $this->line(" └─ Started: {$started}   Finished: {$finished}");
            $this->newLine();
        }

        $this->line('────────────────────────────────────────────────────────────');
        $this->line('Total batches: ' . $batches->count() . '   |   Last refresh: ' . now()->format('H:i:s'));
    }

    /**
     * Detect and handle keypress commands.
     */
    protected function listenForKey(int $staleDays): void
    {
        // Use non-blocking input with error-safety
        $read = [STDIN];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 0, 200000)) {
            $key = trim(strtoupper(fgetc(STDIN)));

            match ($key) {
                'D' => $this->deleteStaleBatches($staleDays),
                'A' => $this->deleteAllBatches(),
                'Q' => exit(0),
                default => null,
            };
        }
    }

    /**
     * Delete stale batches older than X days.
     */
    protected function deleteStaleBatches(int $staleDays): void
    {
        $cutoff = now()->subDays($staleDays);
        $count = DB::table('job_batches')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->delete();

        $this->warn("🗑️ Deleted {$count} stale batches older than {$staleDays} days.");
        sleep(1);
    }

    /**
     * Delete all batches.
     */
    protected function deleteAllBatches(): void
    {
        $count = DB::table('job_batches')->delete();
        $this->warn("🧹 Deleted all ({$count}) job batches.");
        sleep(1);
    }

    /**
     * Clear terminal screen.
     */
    protected function clearScreen(): void
    {
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            system('cls');
        } else {
            echo "\033[2J\033[;H";
        }
    }
}