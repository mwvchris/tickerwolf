<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 *  Migration: add_slug_to_tickers_table
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Adds a `slug` column and composite unique index on (`ticker`, `slug`)
 *   for SEO-friendly routes and canonical ticker lookup.
 *
 * 🧠 Refresh-Safe Features:
 *   • Verifies table and column existence before altering.
 *   • Verifies index existence before adding or dropping.
 *   • Prevents duplicate "_unique_unique" index naming bug.
 *   • Emits clear console output for each step.
 * ============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'tickers';
        $column = 'slug';
        $uniqueIndex = 'tickers_ticker_slug_unique';

        if (!Schema::hasTable($table)) {
            echo "⚠️  Skipping up(): '{$table}' table not found.\n";
            return;
        }

        // 1️⃣ Add the slug column if it doesn't exist
        if (!Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->string($column, 255)->nullable()->after('name')->index();
            });
            echo "✅ Added column '{$column}' to {$table}.\n";
        } else {
            echo "ℹ️  Column '{$column}' already exists — skipping add.\n";
        }

        // 2️⃣ Create the composite unique index if it doesn't exist
        try {
            $exists = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND INDEX_NAME = ?
            ", [$table, $uniqueIndex]);

            if (($exists->cnt ?? 0) == 0) {
                Schema::table($table, function (Blueprint $t) use ($uniqueIndex) {
                    $t->unique(['ticker', 'slug'], $uniqueIndex);
                });
                echo "✅ Created unique index '{$uniqueIndex}' on ({$table}.ticker, {$table}.slug).\n";
            } else {
                echo "ℹ️  Unique index '{$uniqueIndex}' already exists — skipping add.\n";
            }
        } catch (\Throwable $e) {
            echo "⚠️  Skipping unique index creation — error: {$e->getMessage()}\n";
        }
    }

    public function down(): void
    {
        $table = 'tickers';
        $column = 'slug';
        $uniqueIndex = 'tickers_ticker_slug_unique';

        if (!Schema::hasTable($table)) {
            echo "⚠️  Skipping down(): '{$table}' table not found.\n";
            return;
        }

        // 1️⃣ Drop the unique index if it exists
        try {
            $exists = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND INDEX_NAME = ?
            ", [$table, $uniqueIndex]);

            if (($exists->cnt ?? 0) > 0) {
                DB::statement("ALTER TABLE {$table} DROP INDEX {$uniqueIndex};");
                echo "✅ Dropped unique index '{$uniqueIndex}' from {$table}.\n";
            } else {
                echo "⚠️  Skipping drop — index '{$uniqueIndex}' not found.\n";
            }
        } catch (\Throwable $e) {
            echo "⚠️  Skipping index drop '{$uniqueIndex}' — error: {$e->getMessage()}\n";
        }

        // 2️⃣ Drop the slug column if it exists
        if (Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropColumn($column);
            });
            echo "✅ Dropped column '{$column}' from {$table}.\n";
        } else {
            echo "⚠️  Skipping drop — column '{$column}' not found.\n";
        }
    }
};