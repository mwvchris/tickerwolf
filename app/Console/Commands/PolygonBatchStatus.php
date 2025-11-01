<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\JobBatch;
use Illuminate\Support\Facades\DB;

class PolygonBatchStatus extends Command
{
    protected $signature = 'polygon:batches:status
                            {--recent=5 : Show the most recent N batches}
                            {--failed : Show only failed batches}
                            {--active : Show only active (incomplete) batches}';

    protected $description = 'Display recent Polygon batch ingestion statuses and progress.';

    public function handle(): int
    {
        $recent = (int) $this->option('recent');
        $showFailed = $this->option('failed');
        $showActive = $this->option('active');

        $query = JobBatch::query();

        if ($showFailed) {
            $query->where('failed_jobs', '>', 0);
        }

        if ($showActive) {
            $query->where('pending_jobs', '>', 0);
        }

        $batches = $query->orderByDesc('created_at')->limit($recent)->get();

        if ($batches->isEmpty()) {
            $this->warn('No matching batches found.');
            return Command::SUCCESS;
        }

        $rows = $batches->map(function ($batch) {
            $progress = $batch->progress();
            return [
                'ID' => $batch->id,
                'Name' => $batch->name,
                'Created' => $batch->created_at->format('Y-m-d H:i'),
                'Progress' => "{$progress}%",
                'Pending' => $batch->pending_jobs,
                'Failed' => $batch->failed_jobs,
                'Total' => $batch->total_jobs,
            ];
        });

        $this->table(
            ['Batch ID', 'Name', 'Created At', 'Progress', 'Pending', 'Failed', 'Total Jobs'],
            $rows
        );

        return Command::SUCCESS;
    }
}