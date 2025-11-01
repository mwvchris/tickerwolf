<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class PruneJobBatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Examples:
     *   php artisan batches:prune
     *   php artisan batches:prune --days=1 --include-running
     */
    protected $signature = 'batches:prune
                            {--days=3 : Delete batches older than this number of days}
                            {--include-running : Also delete batches that are still marked as running}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = '🧹 Prune old job batch records to keep the batch monitor clean and performant.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $includeRunning = $this->option('include-running');
        $dryRun = $this->option('dry-run');
        $logger = Log::channel('ingest');

        $cutoff = Carbon::now()->subDays($days);

        $query = DB::table('job_batches')
            ->where('created_at', '<', $cutoff);

        if (! $includeRunning) {
            $query->whereNotNull('finished_at');
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info("✅ No batches to prune (older than {$days} days).");
            return Command::SUCCESS;
        }

        $this->warn("Found {$count} batch records older than {$days} days.");
        $this->line('Cutoff date: ' . $cutoff->toDateTimeString());
        $this->line('');

        if ($dryRun) {
            $this->info('🟡 Dry run mode enabled — no deletions performed.');
            return Command::SUCCESS;
        }

        // Confirm deletion if running interactively
        if ($this->input->isInteractive() && ! $this->confirm("Proceed with deleting {$count} old batch records?")) {
            $this->info('❎ Operation cancelled.');
            return Command::SUCCESS;
        }

        // Progress bar for fun
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $deleted = 0;

        DB::table('job_batches')
            ->where('created_at', '<', $cutoff)
            ->when(!$includeRunning, fn($q) => $q->whereNotNull('finished_at'))
            ->orderBy('created_at')
            ->chunkById(100, function ($rows) use (&$deleted, $bar) {
                $ids = collect($rows)->pluck('id');
                DB::table('job_batches')->whereIn('id', $ids)->delete();
                $deleted += $ids->count();
                $bar->advance($ids->count());
            });

        $bar->finish();
        $this->newLine(2);

        $msg = "🧹 Pruned {$deleted} batch records older than {$days} days.";
        $this->info($msg);
        $logger->info($msg, ['days' => $days, 'include_running' => $includeRunning]);

        return Command::SUCCESS;
    }
}