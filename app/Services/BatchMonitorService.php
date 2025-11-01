<?php

namespace App\Services;

use App\Models\JobBatch;
use Illuminate\Support\Facades\Log;
use Throwable;

class BatchMonitorService
{
    /**
     * Create a new batch record.
     */
    public static function createBatch(string $name, int $totalJobs): ?JobBatch
    {
        try {
            return JobBatch::create([
                'name'          => $name,
                'total_jobs'    => $totalJobs,
                'pending_jobs'  => $totalJobs,
                'failed_jobs'   => 0,
                'processed_jobs'=> 0,
                'status'        => 'running',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ Failed to create batch record: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Increment progress for a batch as jobs complete successfully.
     */
    public static function incrementProgress(string $batchName, ?string $ticker = null): void
    {
        try {
            $batch = JobBatch::where('name', $batchName)->latest('id')->first();
            if ($batch) {
                $batch->increment('processed_jobs');
                $batch->decrement('pending_jobs', min(1, $batch->pending_jobs));
                $batch->touch();

                Log::channel('ingest')->info("📈 Batch progress incremented for {$batchName}", [
                    'ticker' => $ticker,
                    'processed_jobs' => $batch->processed_jobs,
                    'pending_jobs'   => $batch->pending_jobs,
                ]);

                // Optionally auto-complete batch if all jobs are done
                if ($batch->pending_jobs <= 0) {
                    $batch->update(['status' => 'complete']);
                }
            } else {
                Log::channel('ingest')->warning("⚠️ incrementProgress: No batch found for {$batchName}");
            }
        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ incrementProgress failed for {$batchName}: {$e->getMessage()}");
        }
    }

    /**
     * Decrement pending count (e.g., when a job finishes).
     */
    public static function decrementPending(JobBatch $jobBatch): void
    {
        try {
            $jobBatch->decrement('pending_jobs');
            $jobBatch->increment('processed_jobs');
            $jobBatch->touch();
        } catch (Throwable $e) {
            Log::channel('ingest')->warning("⚠️ decrementPending failed: {$e->getMessage()}");
        }
    }

    /**
     * Mark a batch job as failed.
     */
    public static function markFailed(JobBatch $jobBatch, string $jobId): void
    {
        try {
            $jobBatch->increment('failed_jobs');
            $jobBatch->decrement('pending_jobs');
            $jobBatch->touch();

            Log::channel('ingest')->error("❌ Job failed in batch [{$jobBatch->name}] - Job ID: {$jobId}");
        } catch (Throwable $e) {
            Log::channel('ingest')->warning("⚠️ markFailed failed: {$e->getMessage()}");
        }
    }
}