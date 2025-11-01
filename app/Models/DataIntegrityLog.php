<?php

namespace App\Models;

/**
 * ============================================================================
 *  DataIntegrityLog (alias of DataValidationLog)
 * ============================================================================
 *
 * A logical alias of DataValidationLog that specifically tracks
 * *integrity-level* audits (e.g., null checks, duplicate detection,
 * orphaned foreign keys, or schema anomalies).
 *
 * Uses the same underlying table `data_validation_logs`.
 * ============================================================================
 */
class DataIntegrityLog extends DataValidationLog
{
    // Uses same fillable, casts, and table
}
