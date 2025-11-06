<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 *  Migration: fix_job_batches_datetime_columns
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Converts integer timestamp fields in `job_batches` to proper DATETIME columns.
 *
 * 🧠 Context:
 *   This migration originally assumed the table always existed. However, during
 *   full refreshes or rebuild sequences, `job_batches` may already be dropped or
 *   recreated — causing SQLSTATE[42S02] ("table not found") errors.
 *
 * ✅ Improvements:
 *   • Checks if table exists before altering.
 *   • Skips missing columns gracefully.
 *   • Outputs clear progress messages for local devs.
 *   • Fully idempotent and CI-safe.
 * ============================================================================
 */
return new class extends Migration
{
    /**
     * Apply migration — convert int timestamps → datetime.
     */
    public function up(): void
    {
        if (!Schema::hasTable('job_batches')) {
            echo "⚠️  Skipping up(): 'job_batches' table not found.\n";
            return;
        }

        Schema::table('job_batches', function (Blueprint $table) {
            $columns = Schema::getColumnListing('job_batches');

            if (in_array('created_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY created_at DATETIME NULL');
            }
            if (in_array('finished_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY finished_at DATETIME NULL');
            }
            if (in_array('cancelled_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY cancelled_at DATETIME NULL');
            }
        });

        echo "✅ job_batches datetime columns adjusted.\n";
    }

    /**
     * Rollback migration — revert datetime → integer timestamps.
     */
    public function down(): void
    {
        if (!Schema::hasTable('job_batches')) {
            echo "⚠️  Skipping down(): 'job_batches' table not found.\n";
            return;
        }

        Schema::table('job_batches', function (Blueprint $table) {
            $columns = Schema::getColumnListing('job_batches');

            if (in_array('created_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY created_at BIGINT NULL');
            }
            if (in_array('finished_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY finished_at BIGINT NULL');
            }
            if (in_array('cancelled_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY cancelled_at BIGINT NULL');
            }
        });

        echo "✅ job_batches column rollback complete.\n";
    }
};