<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\JobBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PolygonBatchCleanup extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'polygon:batches:cleanup
                            {--days=30 : Delete batches older than this number of days}
                            {--completed : Delete only completed batches}
                            {--all : Delete ALL batches regardless of age or status (dangerous)}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up old or completed Polygon job batches to keep the job_batches table lean.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $onlyCompleted = $this->option('completed');
        $deleteAll = $this->option('all');

        // Detect if this is being run by the scheduler (non-interactive)
        $isCron = ! $this->input->isInteractive();

        // Log channel for consistency with other ingestion logs
        $logger = Log::channel('ingest');
        $logger->info('Starting Polygon batch cleanup', [
            'days' => $days,
            'completed_only' => $onlyCompleted,
            'all' => $deleteAll,
            'cron' => $isCron,
        ]);

        // Full deletion safeguard
        if ($deleteAll && !$isCron && !$this->confirm('⚠️  Are you sure you want to delete ALL job batches? This cannot be undone.')) {
            $this->info('Aborted.');
            $logger->info('Batch cleanup aborted by user.');
            return Command::SUCCESS;
        }

        $query = JobBatch::query();

        if ($deleteAll) {
            $count = $query->count();
            $deleted = $query->delete();
            $this->info("Deleted ALL {$deleted} job batches.");
            $logger->info("Deleted all {$deleted} job batches.");
            return Command::SUCCESS;
        }

        // Only completed or aged-out batches
        $cutoff = Carbon::now()->subDays($days);
        $query->where('created_at', '<', $cutoff);

        if ($onlyCompleted) {
            $query->where('pending_jobs', '=', 0);
        }

        $batches = $query->get();
        $count = $batches->count();

        if ($count === 0) {
            $this->info('No matching job batches found for cleanup.');
            $logger->info('No batches found matching cleanup criteria.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$count} job batches older than {$days} days" . ($onlyCompleted ? ' (only completed).' : '.'));

        // Skip confirmation when running from scheduler
        if (!$isCron && !$this->confirm('Proceed with deletion?')) {
            $this->info('Cleanup cancelled.');
            $logger->info('Batch cleanup cancelled by user.');
            return Command::SUCCESS;
        }

        // Perform deletion
        $deleted = JobBatch::whereIn('id', $batches->pluck('id'))->delete();
        $this->info("Successfully deleted {$deleted} batch records.");
        $logger->info("Successfully deleted {$deleted} Polygon job batch records.");

        return Command::SUCCESS;
    }
}