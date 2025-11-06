<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 *  Migration: add_latest_price_index_to_ticker_price_histories_table
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Adds a compound index (`idx_ticker_latest`) on (ticker, t) to accelerate
 *   lookups for the latest price history per ticker.
 *
 * 🧠 Context:
 *   • Originally created using Schema::table() directly.
 *   • That version failed under `migrate:refresh` when the index didn’t exist.
 *   • This version is idempotent — it safely checks for the index and table
 *     before modifying, and never throws if it’s missing.
 *
 * ✅ Safe for:
 *   • `php artisan migrate:refresh`
 *   • `php artisan migrate:fresh`
 *   • CI/CD environment setup
 * ============================================================================
 */
return new class extends Migration
{
    /**
     * Apply the migration.
     */
    public function up(): void
    {
        $table = 'ticker_price_histories';
        $indexName = 'idx_ticker_latest';

        // Skip entirely if the table doesn't exist yet
        if (!Schema::hasTable($table)) {
            echo "⚠️  Skipping up(): '{$table}' table not found.\n";
            return;
        }

        try {
            // Check if index already exists (safe across MariaDB/MySQL)
            $exists = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND INDEX_NAME = ?
            ", [$table, $indexName]);

            if (($exists->cnt ?? 0) == 0) {
                // Add index only if missing
                Schema::table($table, function (Blueprint $t) use ($indexName) {
                    $t->index(['ticker', 't'], $indexName);
                });
                echo "✅ Created index '{$indexName}' on {$table}.\n";
            } else {
                echo "ℹ️  Index '{$indexName}' already exists — skipping add.\n";
            }
        } catch (\Throwable $e) {
            echo "⚠️  Index creation skipped — error: {$e->getMessage()}\n";
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $table = 'ticker_price_histories';
        $indexName = 'idx_ticker_latest';

        if (!Schema::hasTable($table)) {
            echo "⚠️  Skipping down(): '{$table}' table not found.\n";
            return;
        }

        try {
            // Check if index exists before dropping
            $exists = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND INDEX_NAME = ?
            ", [$table, $indexName]);

            if (($exists->cnt ?? 0) > 0) {
                DB::statement("ALTER TABLE {$table} DROP INDEX {$indexName};");
                echo "✅ Dropped index '{$indexName}' from {$table}.\n";
            } else {
                echo "⚠️  Skipping drop — index '{$indexName}' not found.\n";
            }
        } catch (\Throwable $e) {
            echo "⚠️  Skipping drop — error: {$e->getMessage()}\n";
        }
    }
};
