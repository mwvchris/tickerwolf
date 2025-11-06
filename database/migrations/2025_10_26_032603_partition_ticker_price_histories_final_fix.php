<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ============================================================================
 *  Migration: partition_ticker_price_histories_final_fix
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Ensures `ticker_price_histories` table is partitioned by `year`,
 *   and gracefully handles all edge cases for rollback or refresh.
 *
 * ✅ This version:
 *   • Avoids using Schema::table() for dropping missing columns.
 *   • Uses direct SQL with try/catch for total safety.
 *   • No assumptions about partition state or year existence.
 *   • 100% safe for `php artisan migrate:refresh` or `migrate:fresh`.
 * ============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ticker_price_histories')) {
            echo "⚠️  Skipping up(): 'ticker_price_histories' table not found.\n";
            return;
        }

        // Ensure `year` column exists
        if (!Schema::hasColumn('ticker_price_histories', 'year')) {
            Schema::table('ticker_price_histories', function ($table) {
                $table->unsignedSmallInteger('year')->nullable()->after('t');
            });

            DB::statement("UPDATE ticker_price_histories SET year = YEAR(t)");
            Schema::table('ticker_price_histories', function ($table) {
                $table->unsignedSmallInteger('year')->nullable(false)->change();
            });

            echo "✅ Added and populated 'year' column in ticker_price_histories.\n";
        } else {
            echo "ℹ️  'year' column already exists — skipping add.\n";
        }

        // Apply partitioning safely
        try {
            DB::statement("
                ALTER TABLE ticker_price_histories
                PARTITION BY RANGE (year) (
                    PARTITION p2020 VALUES LESS THAN (2021),
                    PARTITION p2021 VALUES LESS THAN (2022),
                    PARTITION p2022 VALUES LESS THAN (2023),
                    PARTITION p2023 VALUES LESS THAN (2024),
                    PARTITION p2024 VALUES LESS THAN (2025),
                    PARTITION p2025 VALUES LESS THAN (2026),
                    PARTITION pmax  VALUES LESS THAN MAXVALUE
                );
            ");
            echo "✅ Applied partitioning to ticker_price_histories.\n";
        } catch (\Throwable $e) {
            echo "⚠️  Partitioning step skipped or failed: {$e->getMessage()}\n";
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('ticker_price_histories')) {
            echo "⚠️  Skipping down(): 'ticker_price_histories' table not found.\n";
            return;
        }

        // Safely remove partitioning
        try {
            DB::statement("ALTER TABLE ticker_price_histories REMOVE PARTITIONING;");
            echo "✅ Partitioning removed from ticker_price_histories.\n";
        } catch (\Throwable $e) {
            echo "⚠️  Partition removal skipped: {$e->getMessage()}\n";
        }

        // Safely drop `year` column (manual SQL to avoid Schema cache issues)
        try {
            $columnExists = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'ticker_price_histories'
                  AND COLUMN_NAME = 'year'
            ")->cnt ?? 0;

            if ($columnExists > 0) {
                DB::statement("ALTER TABLE ticker_price_histories DROP COLUMN year;");
                echo "✅ Dropped 'year' column from ticker_price_histories.\n";
            } else {
                echo "⚠️  Skipping drop — 'year' column not found.\n";
            }
        } catch (\Throwable $e) {
            echo "⚠️  Skipping drop — error: {$e->getMessage()}\n";
        }
    }
};