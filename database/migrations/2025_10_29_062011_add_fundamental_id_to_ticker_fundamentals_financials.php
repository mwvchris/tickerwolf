<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ============================================================================
 *  Migration: Add fundamental_id to ticker_fundamentals_financials (early version)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Introduced an initial `fundamental_id` column linking financials → fundamentals.
 *   This version now mirrors the hardened version used in the later migration
 *   to ensure full rollback compatibility and no SQLSTATE 1091/1072 errors.
 *
 * ============================================================================
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = 'ticker_fundamentals_financials';
        $column = 'fundamental_id';

        if (!Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $table) {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = 'ticker_fundamentals_financials';
        $column = 'fundamental_id';

        // --- Verify existence directly through information_schema ---
        $colExists = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->exists();

        if (!$colExists) {
            echo "✅ Skipping rollback — column '{$column}' not found in {$table}.\n";
            return;
        }

        // --- Drop FK constraints referencing this column (if any) ---
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

        // --- Drop the column safely ---
        try {
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            echo "✅ Column '{$column}' dropped from {$table}.\n";
        } catch (\Throwable $e) {
            echo "⚠️  Column drop skipped: {$e->getMessage()}\n";
        }
    }
};
