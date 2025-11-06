<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 *  Migration: add_performance_indexes_to_ticker_price_histories_table
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Adds key performance indexes and a `latest_ticker_prices` view
 *   to optimize query speed for ticker data lookups.
 *
 * 🧠 Design Notes:
 *   • Adds 3 indexes + 1 unique composite key for fast range & lookup queries.
 *   • Creates (or replaces) a convenience view for retrieving each ticker’s
 *     latest bar data without subqueries in the application layer.
 *
 * ✅ Refresh-Safe Features:
 *   • Checks if the table exists before altering.
 *   • Verifies index existence before adding or dropping.
 *   • Catches SQL errors gracefully (migrate:refresh, CI, or re-runs).
 *   • Drops the view only if it exists.
 *
 * ============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'ticker_price_histories';
        $view = 'latest_ticker_prices';

        if (!Schema::hasTable($table)) {
            echo "⚠️  Skipping up(): '{$table}' table not found.\n";
            return;
        }

        $indexes = [
            'ticker_t_index' => ['ticker', 't'],
            'ticker_resolution_index' => ['ticker', 'resolution'],
            't_index' => ['t'],
        ];

        // Add performance indexes if missing
        foreach ($indexes as $indexName => $columns) {
            try {
                $exists = DB::selectOne("
                    SELECT COUNT(*) AS cnt
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = ?
                      AND INDEX_NAME = ?
                ", [$table, $indexName]);

                if (($exists->cnt ?? 0) == 0) {
                    Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                        $t->index($columns, $indexName);
                    });
                    echo "✅ Created index '{$indexName}' on {$table} (" . implode(', ', $columns) . ").\n";
                } else {
                    echo "ℹ️  Index '{$indexName}' already exists — skipping add.\n";
                }
            } catch (\Throwable $e) {
                echo "⚠️  Skipping add '{$indexName}' — error: {$e->getMessage()}\n";
            }
        }

        // Add unique composite key if missing
        try {
            $uniqueExists = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND CONSTRAINT_TYPE = 'UNIQUE'
                  AND CONSTRAINT_NAME = 'unique_ticker_t_resolution'
            ", [$table]);

            if (($uniqueExists->cnt ?? 0) == 0) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unique(['ticker', 't', 'resolution'], 'unique_ticker_t_resolution');
                });
                echo "✅ Created unique key 'unique_ticker_t_resolution' on {$table} (ticker, t, resolution).\n";
            } else {
                echo "ℹ️  Unique key 'unique_ticker_t_resolution' already exists — skipping add.\n";
            }
        } catch (\Throwable $e) {
            echo "⚠️  Skipping unique key creation — error: {$e->getMessage()}\n";
        }

        // Create or replace view for latest ticker prices
        try {
            DB::statement("
                CREATE OR REPLACE VIEW {$view} AS
                SELECT tph.*
                FROM {$table} tph
                INNER JOIN (
                    SELECT ticker, MAX(t) AS latest_t
                    FROM {$table}
                    GROUP BY ticker
                ) latest
                ON tph.ticker = latest.ticker AND tph.t = latest.latest_t
            ");
            echo "✅ Created or replaced view '{$view}'.\n";
        } catch (\Throwable $e) {
            echo "⚠️  Skipping view creation — error: {$e->getMessage()}\n";
        }
    }

    public function down(): void
    {
        $table = 'ticker_price_histories';
        $view = 'latest_ticker_prices';

        if (!Schema::hasTable($table)) {
            echo "⚠️  Skipping down(): '{$table}' table not found.\n";
            return;
        }

        $indexes = [
            'ticker_t_index',
            'ticker_resolution_index',
            't_index',
            'unique_ticker_t_resolution',
        ];

        foreach ($indexes as $indexName) {
            try {
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
                echo "⚠️  Skipping drop '{$indexName}' — error: {$e->getMessage()}\n";
            }
        }

        // Drop the view if it exists
        try {
            DB::statement("DROP VIEW IF EXISTS {$view};");
            echo "✅ Dropped view '{$view}'.\n";
        } catch (\Throwable $e) {
            echo "⚠️  Skipping view drop — error: {$e->getMessage()}\n";
        }
    }
};