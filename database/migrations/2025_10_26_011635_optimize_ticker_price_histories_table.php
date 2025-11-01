<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * NOTE:
     * - This migration is intentionally defensive. It will:
     *   1) Add a generated column `t_year` storing YEAR(t)
     *   2) Create helpful indexes for common queries
     *   3) Attempt to partition the table by YEAR using RANGE on t_year
     *
     * WARNING:
     * - Partitioning an existing large table may take a long time and lock the table.
     * - Test in staging and/or run during a maintenance window.
     */

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = 'ticker_price_histories';

        // 1) Add generated column t_year if it doesn't exist
        $hasColumn = DB::selectOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 't_year'", [$table])->cnt ?? 0;
        if (! $hasColumn) {
            // Use STORED generated column so it can be indexed and used for partitioning
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `t_year` INT GENERATED ALWAYS AS (YEAR(`t`)) STORED");
        }

        // 2) Add / ensure indexes
        // Composite index for (ticker_id, resolution, t) - useful for time-range queries per ticker
        $indexExists = DB::selectOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_ticker_resolution_t'", [$table])->cnt ?? 0;
        if (! $indexExists) {
            DB::statement("CREATE INDEX idx_ticker_resolution_t ON `{$table}` (`ticker_id`, `resolution`, `t`)");
        }

        // Index by date (t) for range queries across tickers
        $idxT = DB::selectOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_t'", [$table])->cnt ?? 0;
        if (! $idxT) {
            DB::statement("CREATE INDEX idx_t ON `{$table}` (`t`)");
        }

        // Index on t_year to help partition pruning / queries
        $idxYear = DB::selectOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_t_year'", [$table])->cnt ?? 0;
        if (! $idxYear) {
            DB::statement("CREATE INDEX idx_t_year ON `{$table}` (`t_year`)");
        }

        // 3) Attempt to partition the table by RANGE on t_year
        // Build partitions from 2015 through next year, plus MAXVALUE
        $currentYear = (int) date('Y');
        $startYear = 2015;
        $endYear = $currentYear + 1;

        // Check if the table is already partitioned
        $isPartitioned = DB::selectOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table])->cnt ?? 0;

        if (! $isPartitioned) {
            $parts = [];
            for ($y = $startYear; $y <= $endYear; $y++) {
                $label = "p{$y}";
                $lessThan = $y + 1; // partition holds years < (y+1) => year == y
                $parts[] = "PARTITION `{$label}` VALUES LESS THAN ({$lessThan})";
            }
            $parts[] = "PARTITION `pmax` VALUES LESS THAN (MAXVALUE)";
            $partSql = implode(",\n    ", $parts);

            $partitionStmt = "ALTER TABLE `{$table}` PARTITION BY RANGE (`t_year`) (\n    {$partSql}\n)";

            // Try to run partitioning; wrap in try/catch because this may fail on some engines or existing data.
            try {
                DB::statement($partitionStmt);
            } catch (\Throwable $e) {
                // Partitioning can fail on large tables or specific server configs.
                // Log the error to the Laravel log and continue; an operator can run partitioning manually.
                \Illuminate\Support\Facades\Log::warning("Partitioning attempt failed for {$table}: " . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = 'ticker_price_histories';

        // Remove partitioning if present - this operation can be heavy.
        try {
            // Only try if partitions exist
            $isPartitioned = DB::selectOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table])->cnt ?? 0;
            if ($isPartitioned) {
                DB::statement("ALTER TABLE `{$table}` REMOVE PARTITIONING");
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to remove partitioning for {$table}: " . $e->getMessage());
        }

        // Drop the generated column if it exists
        $hasColumn = DB::selectOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 't_year'", [$table])->cnt ?? 0;
        if ($hasColumn) {
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `t_year`");
        }

        // Drop indexes we created (ignore errors)
        try {
            DB::statement("DROP INDEX idx_ticker_resolution_t ON `{$table}`");
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            DB::statement("DROP INDEX idx_t ON `{$table}`");
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            DB::statement("DROP INDEX idx_t_year ON `{$table}`");
        } catch (\Throwable $e) {
            // ignore
        }
    }
};