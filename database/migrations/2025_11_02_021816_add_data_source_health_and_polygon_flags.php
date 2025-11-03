<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ============================================================================
 *  Migration: add_data_source_health_and_polygon_flags
 * ============================================================================
 *
 * 🔧 Purpose:
 *   • Adds upstream health tracking + Polygon status flags.
 *   • Enables root-cause analysis for missing data (local vs. upstream).
 *
 * 📦 Tables affected:
 *   • tickers
 *   • data_validation_logs
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
        /*
        |--------------------------------------------------------------------------
        | Extend tickers table
        |--------------------------------------------------------------------------
        */
        Schema::table('tickers', function (Blueprint $table) {
            // Pick the best reference column dynamically (some schemas use "active")
            $afterColumn = Schema::hasColumn('tickers', 'is_active')
                ? 'is_active'
                : (Schema::hasColumn('tickers', 'active') ? 'active' : 'status');

            if (!Schema::hasColumn('tickers', 'is_active_polygon')) {
                $table->boolean('is_active_polygon')
                    ->default(true)
                    ->after($afterColumn)
                    ->comment('Indicates if Polygon.io has valid upstream data for this ticker');
            }

            if (!Schema::hasColumn('tickers', 'deactivation_reason')) {
                $table->string('deactivation_reason', 255)
                    ->nullable()
                    ->after('is_active_polygon')
                    ->comment('Human-readable reason for deactivation (e.g. delisted, no_data_from_polygon)');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Extend data_validation_logs table
        |--------------------------------------------------------------------------
        */
        Schema::table('data_validation_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('data_validation_logs', 'data_source_health')) {
                $table->json('data_source_health')
                    ->nullable()
                    ->after('details')
                    ->comment('JSON blob describing upstream API health or probe results');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickers', function (Blueprint $table) {
            if (Schema::hasColumn('tickers', 'is_active_polygon')) {
                $table->dropColumn('is_active_polygon');
            }
            if (Schema::hasColumn('tickers', 'deactivation_reason')) {
                $table->dropColumn('deactivation_reason');
            }
        });

        Schema::table('data_validation_logs', function (Blueprint $table) {
            if (Schema::hasColumn('data_validation_logs', 'data_source_health')) {
                $table->dropColumn('data_source_health');
            }
        });
    }
};