<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ============================================================================
 *  Migration: Add fundamental_id to ticker_fundamentals_financials
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Adds a nullable `fundamental_id` column to link financial rows back
 *   to their parent record in `ticker_fundamentals`.
 *
 * 🧩 Features:
 *   - Adds column + FK if not present.
 *   - Drops FK + column safely using direct schema checks (no cache).
 *   - Skips gracefully on all dev/CI environments.
 *
 * ============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ticker_fundamentals_financials', 'fundamental_id')) {
            Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
                $table->unsignedBigInteger('fundamental_id')->nullable()->after('id');

                if (Schema::hasTable('ticker_fundamentals')) {
                    $table->foreign('fundamental_id')
                          ->references('id')
                          ->on('ticker_fundamentals')
                          ->onDelete('cascade');
                }
            });
        }
    }

    public function down(): void
    {
        $table = 'ticker_fundamentals_financials';
        $column = 'fundamental_id';

        // Check directly via information_schema to avoid Laravel's cache
        $colExists = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->exists();

        if (!$colExists) {
            echo "✅ Skipping rollback — column '{$column}' not found in {$table}.\n";
            return;
        }

        // Drop FK(s) if present
        $constraints = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$table, $column]);

        foreach ($constraints as $fk) {
            $fkName = $fk->CONSTRAINT_NAME;
            echo "🧹 Dropping FK constraint '{$fkName}' on {$table}...\n";
            try {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
            } catch (\Throwable $e) {
                echo "⚠️  Could not drop FK '{$fkName}': {$e->getMessage()}\n";
            }
        }

        // Drop column directly, bypassing Schema cache
        try {
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            echo "✅ Column '{$column}' dropped from {$table}.\n";
        } catch (\Throwable $e) {
            echo "⚠️  Column drop skipped: {$e->getMessage()}\n";
        }
    }
};