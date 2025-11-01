<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Ensure 'year' column exists
        if (!Schema::hasColumn('ticker_price_histories', 'year')) {
            Schema::table('ticker_price_histories', function ($table) {
                $table->unsignedSmallInteger('year')->nullable()->after('t');
            });

            DB::statement("UPDATE ticker_price_histories SET year = YEAR(t)");
            Schema::table('ticker_price_histories', function ($table) {
                $table->unsignedSmallInteger('year')->nullable(false)->change();
            });
        }

        // Step 2: Rebuild primary key safely
        // First check if 'year' is already part of the primary key
        $indexes = DB::select("SHOW INDEX FROM ticker_price_histories WHERE Key_name = 'PRIMARY'");
        $hasYear = collect($indexes)->contains(fn($i) => $i->Column_name === 'year');

        if (!$hasYear) {
            // Use MODIFY instead of DROP+ADD to preserve AUTO_INCREMENT
            DB::statement('ALTER TABLE ticker_price_histories MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
            DB::statement('ALTER TABLE ticker_price_histories DROP PRIMARY KEY, ADD PRIMARY KEY (id, year)');
        }

        // Step 3: Drop and recreate any unique indexes that exclude 'year'
        $uniqueKeys = DB::select("
            SELECT DISTINCT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'ticker_price_histories'
              AND NON_UNIQUE = 0
              AND INDEX_NAME != 'PRIMARY'
        ");

        foreach ($uniqueKeys as $key) {
            $cols = DB::select("
                SELECT COLUMN_NAME
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'ticker_price_histories'
                  AND INDEX_NAME = ?
                ORDER BY SEQ_IN_INDEX
            ", [$key->INDEX_NAME]);

            $colNames = collect($cols)->pluck('COLUMN_NAME')->toArray();
            if (!in_array('year', $colNames)) {
                DB::statement("ALTER TABLE ticker_price_histories DROP INDEX `{$key->INDEX_NAME}`");
                DB::statement("ALTER TABLE ticker_price_histories ADD UNIQUE KEY `{$key->INDEX_NAME}_year` (" . implode(',', $colNames) . ", year)");
            }
        }

        // Step 4: Apply partitioning
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
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to apply partitioning: ' . $e->getMessage());
        }
    }

    
    public function down(): void
    {
        // rollback only drops partitioning and 'year'
        try {
            DB::statement("ALTER TABLE ticker_price_histories REMOVE PARTITIONING");
        } catch (\Throwable $e) {
            // ignore if already removed
        }

        if (Schema::hasColumn('ticker_price_histories', 'year')) {
            Schema::table('ticker_price_histories', function ($table) {
                $table->dropColumn('year');
            });
        }
    }
};