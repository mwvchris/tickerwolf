<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 *  Migration: fix_timestamps_in_job_batches_table
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Converts `created_at` and `updated_at` columns in `job_batches`
 *   between DATETIME and TIMESTAMP for consistent schema alignment.
 *
 * 💡 Context:
 *   During `migrate:refresh`, this table may already be dropped or rebuilt
 *   by later migrations (e.g., `rebuild_job_batches_table`), causing
 *   SQLSTATE[42S02] errors if not checked.
 *
 * ✅ Improvements:
 *   • Verifies table existence before altering.
 *   • Skips missing columns gracefully.
 *   • Provides clear console output for visibility.
 *   • Fully idempotent and refresh-safe for CI/CD pipelines.
 * ============================================================================
 */
return new class extends Migration
{
    /**
     * Apply migration — convert TIMESTAMP → DATETIME.
     */
    public function up(): void
    {
        if (!Schema::hasTable('job_batches')) {
            echo "⚠️  Skipping up(): 'job_batches' table not found.\n";
            return;
        }

        $columns = Schema::getColumnListing('job_batches');

        Schema::table('job_batches', function (Blueprint $table) use ($columns) {
            if (in_array('created_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY created_at DATETIME NULL');
            }
            if (in_array('updated_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY updated_at DATETIME NULL');
            }
        });

        echo "✅ job_batches columns converted to DATETIME (if existed).\n";
    }

    /**
     * Rollback migration — convert DATETIME → TIMESTAMP.
     */
    public function down(): void
    {
        if (!Schema::hasTable('job_batches')) {
            echo "⚠️  Skipping down(): 'job_batches' table not found.\n";
            return;
        }

        $columns = Schema::getColumnListing('job_batches');

        Schema::table('job_batches', function (Blueprint $table) use ($columns) {
            if (in_array('created_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY created_at TIMESTAMP NULL');
            }
            if (in_array('updated_at', $columns)) {
                DB::statement('ALTER TABLE job_batches MODIFY updated_at TIMESTAMP NULL');
            }
        });

        echo "✅ job_batches columns reverted to TIMESTAMP (if existed).\n";
    }
};
