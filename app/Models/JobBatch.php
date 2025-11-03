<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * ============================================================================
 *  JobBatch Model (Final - Eloquent-safe)
 * ============================================================================
 *
 * Represents records in the `job_batches` table, used by:
 *   • Laravel's native Bus batching system
 *   • TickerWolf's custom ingestion monitoring layer
 *
 * Key behaviors:
 *   • UUID primary keys
 *   • Hybrid timestamp model:
 *       - created_at, finished_at, cancelled_at → UNIX epoch (INT)
 *       - updated_at → DATETIME (timestamp)
 *   • Disables Eloquent’s built-in timestamp mutation to prevent
 *     overwriting of integer timestamps with formatted datetime strings.
 * ============================================================================
 */
class JobBatch extends Model
{
    use HasFactory;

    /** @var string The table associated with this model. */
    protected $table = 'job_batches';

    /** @var bool Indicates if the model's primary key is auto-incrementing. */
    public $incrementing = false;

    /** @var string The data type of the primary key. */
    protected $keyType = 'string';

    /**
     * Disable automatic timestamp management.
     *
     * We handle created_at/updated_at manually to keep schema consistency.
     */
    public $timestamps = false; // ✅ CRUCIAL FIX

    /** @var array Attributes that can be mass-assigned. */
    protected $fillable = [
        'id',
        'name',
        'total_jobs',
        'pending_jobs',
        'processed_jobs',
        'failed_jobs',
        'status',
        'created_at',
        'updated_at',
    ];

    /** @var array Attribute casting definitions. */
    protected $casts = [
        'total_jobs'     => 'integer',
        'pending_jobs'   => 'integer',
        'processed_jobs' => 'integer',
        'failed_jobs'    => 'integer',
        'created_at'     => 'integer',   // UNIX timestamp
        'updated_at'     => 'datetime',  // full timestamp
    ];

    /**
     * Boot the model and handle UUID + manual timestamps.
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            // Generate UUID primary key
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }

            // Only set timestamps manually
            if (empty($model->created_at)) {
                $model->created_at = now()->getTimestamp();
            }
            if (empty($model->updated_at)) {
                $model->updated_at = now();
            }
        });
    }

    /* ----------------------------------------------------------------------
     |  Domain convenience helpers
     | ---------------------------------------------------------------------- */

    /** Determine if the batch is complete. */
    public function isComplete(): bool
    {
        return $this->pending_jobs <= 0 && $this->failed_jobs === 0;
    }

    /** Determine if the batch has failures. */
    public function hasFailures(): bool
    {
        return $this->failed_jobs > 0;
    }

    /** Mark the batch as complete. */
    public function markComplete(): void
    {
        $this->update(['status' => 'complete']);
    }

    /** Mark the batch as failed. */
    public function markFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
}