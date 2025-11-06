<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 *  Migration: safely_remove_ticker_column_from_ticker_price_histories_table
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Removes the legacy `ticker` column and its associated indexes from the
 *   `ticker_price_histories` table. This was previously used for symbol strings
 *   (e.g., "AAPL") before normalization to `ticker_id`.
 *
 * 🧠 Refresh-Safe Features:
 *   • Checks for table and column existence before altering.
 *   • Verifies each index before attempting to drop.
 *   • Emits detailed CLI messages for clarity during migrations.
 *   • Restores column + index safely in `down()`.
 * ============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'ticker_price_histories';
        $column = 'ticker';
        $indexes = [
            'unique_ticker_t_resolution_year',
            'ticker_t_index',
            'ticker_resolution_index',
            'ticker_price_histories_ticker_index',
            'idx_ticker_latest',
        ];

        if (!Schema::hasTable($table)) {
            echo "⚠️  Skipping up(): '{$table}' table not found.\n";
            return;
        }

        // 1️⃣ Drop indexes referencing the ticker column
        foreach ($indexes as $index) {
            try {
                $exists = DB::selectOne("
                    SELECT COUNT(*) AS cnt
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = ?
                      AND INDEX_NAME = ?
                ", [$table, $index]);

                if (($exists->cnt ?? 0) > 0) {
                    DB::statement("ALTER TABLE {$table} DROP INDEX `{$index}`;");
                    echo "✅ Dropped index '{$index}' from {$table}.\n";
                } else {
                    echo "⚠️  Skipping drop — index '{$index}' not found.\n";
                }
            } catch (\Throwable $e) {
                echo "⚠️  Could not drop index '{$index}': {$e->getMessage()}\n";
            }
        }

        // 2️⃣ Drop the legacy `ticker` column if it exists
        if (Schema::hasColumn($table, $column)) {
            try {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
                echo "✅ Dropped column '{$column}' from {$table}.\n";
            } catch (\Throwable $e) {
                echo "⚠️  Skipping column drop — error: {$e->getMessage()}\n";
            }
        } else {
            echo "ℹ️  Column '{$column}' already absent — skipping drop.\n";
        }
    }

    public function down(): void
    {
        $table = 'ticker_price_histories';
        $column = 'ticker';

        if (!Schema::hasTable($table)) {
            echo "⚠️  Skipping down(): '{$table}' table not found.\n";
            return;
        }

        // 1️⃣ Restore column if missing
        if (!Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->string($column, 16)->nullable()->after('ticker_id');
            });
            echo "✅ Restored column '{$column}' to {$table}.\n";
        } else {
            echo "ℹ️  Column '{$column}' already exists — skipping restore.\n";
        }

        // 2️⃣ Optionally restore an index for backward compatibility
        try {
            $exists = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
            ", [$table, $column]);

            if (($exists->cnt ?? 0) == 0) {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->index($column, 'ticker_price_histories_ticker_index');
                });
                echo "✅ Restored index 'ticker_price_histories_ticker_index' on '{$column}'.\n";
            } else {
                echo "ℹ️  Index already exists for '{$column}' — skipping restore.\n";
            }
        } catch (\Throwable $e) {
            echo "⚠️  Could not restore index for '{$column}': {$e->getMessage()}\n";
        }
    }
};