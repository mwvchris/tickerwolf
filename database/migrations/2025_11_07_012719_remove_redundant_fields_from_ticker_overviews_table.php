<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Remove redundant fields from ticker_overviews table
 *
 * Purpose:
 *   The `ticker_overviews` table originally duplicated two columns —
 *   `locale` and `primary_exchange` — which also exist in the `tickers` table.
 *   These values are static company metadata, not time-varying metrics,
 *   so they are being removed to prevent redundancy and ensure consistency.
 *
 * Notes:
 *   - Safe "hasColumn" checks are used to prevent errors during rollback.
 *   - This migration is backward-compatible with old data exports.
 */
return new class extends Migration
{
    /**
     * Run the migrations (remove redundant columns).
     */
    public function up(): void
    {
        if (Schema::hasTable('ticker_overviews')) {
            Schema::table('ticker_overviews', function (Blueprint $table) {
                if (Schema::hasColumn('ticker_overviews', 'locale')) {
                    $table->dropColumn('locale');
                }

                if (Schema::hasColumn('ticker_overviews', 'primary_exchange')) {
                    $table->dropColumn('primary_exchange');
                }
            });
        }
    }

    /**
     * Reverse the migrations (restore columns if rolled back).
     */
    public function down(): void
    {
        if (Schema::hasTable('ticker_overviews')) {
            Schema::table('ticker_overviews', function (Blueprint $table) {
                if (!Schema::hasColumn('ticker_overviews', 'locale')) {
                    $table->string('locale')->nullable()->after('market_cap')->index();
                }

                if (!Schema::hasColumn('ticker_overviews', 'primary_exchange')) {
                    $table->string('primary_exchange')->nullable()->after('market_cap')->index();
                }
            });
        }
    }
};