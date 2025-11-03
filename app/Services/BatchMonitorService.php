<?php

namespace App\Services;

use App\Models\JobBatch;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 *  BatchMonitorService
 * ============================================================================
 *
 *  This service provides a thin abstraction layer around the `job_batches`
 *  table, which is managed by both:
 *      • Laravel's native Bus batching system (via Bus::batch)
 *      • TickerWolf's custom ingestion orchestration layer
 *
 *  It allows ingestion commands, cron jobs, and queued tasks to:
 *      - Create new batch records
 *      - Track incremental progress and completion
 *      - Record failures and pending counts
 *      - Keep a hybrid timestamp model:
 *          • `created_at`, `finished_at`, `cancelled_at` → UNIX epoch (INT)
 *          • `updated_at` → full DATETIME (timestamp)
 *
 *  This dual timestamp model ensures full compatibility with:
 *      • Laravel’s core `Illuminate\Bus\DatabaseBatchRepository`
 *      • Custom ORM-based progress tracking and log annotations
 * ============================================================================
 */
class BatchMonitorService
{
    /**
     * ------------------------------------------------------------------------
     *  Create a new batch record
     * ------------------------------------------------------------------------
     *  Creates an entry in the `job_batches` table to represent a new
     *  ingestion or compute batch. This ensures a unified view between
     *  Laravel's Bus repository and our custom ingestion monitor.
     *
     * @param  string  $name       Human-readable batch name (e.g. command name)
     * @param  int     $totalJobs  Total number of jobs in the batch
     * @return JobBatch|null
     */
    public static function createBatch(string $name, int $totalJobs): ?JobBatch
    {
        try {
            return JobBatch::create([
                'name'           => $name,
                'total_jobs'     => $totalJobs,
                'pending_jobs'   => $totalJobs,
                'failed_jobs'    => 0,
                'processed_jobs' => 0,
                'status'         => 'running',

                // ✅ Use UNIX timestamps (INT) for Laravel Bus compatibility
                'created_at'     => now()->getTimestamp(),

                // ✅ Keep updated_at as full DATETIME for Eloquent touch()
                'updated_at'     => now(),
            ]);
        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ Failed to create batch record", [
                'batch'  => $name,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * ------------------------------------------------------------------------
     *  Increment progress as jobs complete successfully
     * ------------------------------------------------------------------------
     *  Increments the processed count, decrements pending jobs,
     *  and automatically marks the batch complete if all jobs finish.
     *
     * @param  string       $batchName  Batch name to update
     * @param  string|null  $ticker     Optional ticker symbol for context
     * @return void
     */
    public static function incrementProgress(string $batchName, ?string $ticker = null): void
    {
        try {
            $batch = JobBatch::where('name', $batchName)->latest('id')->first();

            if ($batch) {
                $batch->increment('processed_jobs');
                $batch->decrement('pending_jobs', min(1, $batch->pending_jobs));
                $batch->touch(); // updates updated_at (DATETIME)

                Log::channel('ingest')->info("📈 Batch progress incremented", [
                    'batch'          => $batchName,
                    'ticker'         => $ticker,
                    'processed_jobs' => $batch->processed_jobs,
                    'pending_jobs'   => $batch->pending_jobs,
                ]);

                // Auto-complete once all jobs are finished
                if ($batch->pending_jobs <= 0) {
                    $batch->update(['status' => 'complete']);
                    Log::channel('ingest')->info("✅ Batch marked complete", [
                        'batch' => $batchName,
                    ]);
                }
            } else {
                Log::channel('ingest')->warning("⚠️ incrementProgress: No batch found", [
                    'batch' => $batchName,
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ incrementProgress failed", [
                'batch' => $batchName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ------------------------------------------------------------------------
     *  Decrement pending count (e.g., when a job finishes)
     * ------------------------------------------------------------------------
     *  Manually decrements the `pending_jobs` counter and increments
     *  `processed_jobs`, ensuring real-time batch progress visibility.
     *
     * @param  JobBatch  $jobBatch
     * @return void
     */
    public static function decrementPending(JobBatch $jobBatch): void
    {
        try {
            $jobBatch->decrement('pending_jobs');
            $jobBatch->increment('processed_jobs');
            $jobBatch->touch();
        } catch (Throwable $e) {
            Log::channel('ingest')->warning("⚠️ decrementPending failed", [
                'batch' => $jobBatch->name ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ------------------------------------------------------------------------
     *  Mark a batch job as failed
     * ------------------------------------------------------------------------
     *  Records a failed job within the batch, increments `failed_jobs`,
     *  decrements pending, and logs diagnostic details.
     *
     * @param  JobBatch  $jobBatch  The batch model instance
     * @param  string    $jobId     The job UUID or identifier
     * @return void
     */
    public static function markFailed(JobBatch $jobBatch, string $jobId): void
    {
        try {
            $jobBatch->increment('failed_jobs');
            $jobBatch->decrement('pending_jobs');
            $jobBatch->touch();

            Log::channel('ingest')->error("❌ Job failed in batch", [
                'batch' => $jobBatch->name,
                'job_id' => $jobId,
            ]);
        } catch (Throwable $e) {
            Log::channel('ingest')->warning("⚠️ markFailed failed", [
                'batch' => $jobBatch->name ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }
}