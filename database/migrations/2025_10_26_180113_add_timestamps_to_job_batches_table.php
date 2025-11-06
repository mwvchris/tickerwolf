<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ============================================================================
 *  Migration: add_timestamps_to_job_batches_table
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Adds standard `created_at` and `updated_at` timestamps to the
 *   `job_batches` table if they don't already exist.
 *
 * 💡 Context:
 *   This migration may fail during `migrate:refresh` if `job_batches`
 *   has already been dropped or rebuilt by later migrations.
 *
 * ✅ Improvements:
 *   • Checks for table existence before altering.
 *   • Adds missing columns only if not present.
 *   • Skips gracefully (with console output) if table/columns missing.
 *   • CI-safe, idempotent, and rollback-safe.
 * ============================================================================
 */
return new class extends Migration
{
    /**
     * Apply migration — add timestamps to job_batches.
     */
    public function up(): void
    {
        if (!Schema::hasTable('job_batches')) {
            echo "⚠️  Skipping up(): 'job_batches' table not found.\n";
            return;
        }

        Schema::table('job_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('job_batches', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('job_batches', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        echo "✅ job_batches timestamps added (if missing).\n";
    }

    /**
     * Rollback migration — drop the added timestamp columns.
     */
    public function down(): void
    {
        if (!Schema::hasTable('job_batches')) {
            echo "⚠️  Skipping down(): 'job_batches' table not found.\n";
            return;
        }

        Schema::table('job_batches', function (Blueprint $table) {
            if (Schema::hasColumn('job_batches', 'created_at')) {
                $table->dropColumn('created_at');
            }
            if (Schema::hasColumn('job_batches', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });

        echo "✅ job_batches timestamps dropped (if existed).\n";
    }
};