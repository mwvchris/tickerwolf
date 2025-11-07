<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Remove market_cap from tickers table
 *
 * Purpose:
 *   - The `market_cap` field fluctuates frequently (daily/real-time),
 *     so it belongs in `ticker_overviews`, not `tickers`.
 *   - This migration safely removes the redundant column.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('tickers') && Schema::hasColumn('tickers', 'market_cap')) {
            Schema::table('tickers', function (Blueprint $table) {
                $table->dropColumn('market_cap');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tickers') && !Schema::hasColumn('tickers', 'market_cap')) {
            Schema::table('tickers', function (Blueprint $table) {
                $table->unsignedBigInteger('market_cap')->nullable()->after('list_date')->index();
            });
        }
    }
};
