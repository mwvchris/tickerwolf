<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ticker_feature_snapshots')) {
            return;
        }

        Schema::table('ticker_feature_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('ticker_feature_snapshots', 'indicators')) {
                $table->longText('indicators')
                    ->nullable()
                    ->collation('utf8mb4_bin')
                    ->after('t');
            }
        });

        // Add JSON validity check (safe fallback for MariaDB/MySQL)
        try {
            DB::statement("ALTER TABLE `ticker_feature_snapshots`
                ADD CONSTRAINT `chk_tfs_indicators_json`
                CHECK (JSON_VALID(`indicators`))");
        } catch (\Throwable $e) {
            // MariaDB <10.4 or MySQL <5.7 may skip this silently
        }

        // Add composite index if it doesn't already exist
        if (!$this->indexExists('ticker_feature_snapshots', 'idx_tfs_ticker_t')) {
            Schema::table('ticker_feature_snapshots', function (Blueprint $table) {
                $table->index(['ticker_id', 't'], 'idx_tfs_ticker_t');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('ticker_feature_snapshots')) {
            return;
        }

        Schema::table('ticker_feature_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('ticker_feature_snapshots', 'indicators')) {
                $table->dropColumn('indicators');
            }
            if ($this->indexExists('ticker_feature_snapshots', 'idx_tfs_ticker_t')) {
                $table->dropIndex('idx_tfs_ticker_t');
            }
        });

        try {
            DB::statement("ALTER TABLE `ticker_feature_snapshots`
                DROP CONSTRAINT `chk_tfs_indicators_json`");
        } catch (\Throwable $e) {
            // Safe to ignore
        }
    }

    /**
     * Cross-DB safe index existence check using SHOW INDEX.
     */
    private function indexExists(string $table, string $index): bool
    {
        try {
            $dbName = DB::getDatabaseName();
            $res = DB::select("
                SELECT COUNT(1) AS found
                FROM information_schema.statistics
                WHERE table_schema = ? AND table_name = ? AND index_name = ?
                LIMIT 1
            ", [$dbName, $table, $index]);

            return isset($res[0]) && (int)$res[0]->found > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
};