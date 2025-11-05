<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ============================================================================
 *  Migration: Increase precision for `vw` in ticker_price_histories
 * ============================================================================
 * Resolves occasional SQLSTATE[22003] numeric overflow errors when Polygon.io
 * returns large "volume-weighted average price" values (e.g., 2,448,000+).
 * This aligns `vw` with o/h/l/c decimal precision for uniformity.
 * ============================================================================
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ticker_price_histories', function (Blueprint $table) {
            $table->decimal('vw', 16, 6)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_price_histories', function (Blueprint $table) {
            $table->decimal('vw', 12, 6)->nullable()->change();
        });
    }
};