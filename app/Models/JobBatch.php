<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobBatch extends Model
{
    use HasFactory;

    protected $table = 'job_batches';

    protected $fillable = [
        'name',
        'total_jobs',
        'pending_jobs',
        'processed_jobs',
        'failed_jobs',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'total_jobs'    => 'integer',
        'pending_jobs'  => 'integer',
        'processed_jobs'=> 'integer',
        'failed_jobs'   => 'integer',
    ];

    /**
     * Determine if the batch is complete.
     */
    public function isComplete(): bool
    {
        return $this->pending_jobs <= 0 && $this->failed_jobs === 0;
    }

    /**
     * Determine if the batch has failures.
     */
    public function hasFailures(): bool
    {
        return $this->failed_jobs > 0;
    }

    /**
     * Mark the batch as complete.
     */
    public function markComplete(): void
    {
        $this->update(['status' => 'complete']);
    }

    /**
     * Mark the batch as failed.
     */
    public function markFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
}