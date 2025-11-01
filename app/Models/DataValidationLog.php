<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 *  DataValidationLog
 * ============================================================================
 *
 * Represents a persisted record of a validation run — used by:
 *  - tickers:validate-data
 *  - tickers:integrity-scan
 *  - (future) sentiment & fundamentals validation jobs
 *
 * Each record stores summary metrics, details JSON, and run metadata.
 * ============================================================================
 */
class DataValidationLog extends Model
{
    protected $table = 'data_validation_logs';

    protected $fillable = [
        'entity_type',
        'command_name',
        'total_entities',
        'validated_count',
        'missing_count',
        'details',
        'status',
        'started_at',
        'completed_at',
        'initiated_by',
    ];

    protected $casts = [
        'details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}