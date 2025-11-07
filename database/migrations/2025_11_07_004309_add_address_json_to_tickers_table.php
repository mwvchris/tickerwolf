<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Add address_json column to tickers table
 *
 * Purpose:
 *   - Store the structured address block returned by Polygon.io’s
 *     /v3/reference/tickers/{symbol} endpoint (address1, city, state, postal_code).
 *   - The column uses JSON type for flexibility and to support direct
 *     filtering via MariaDB/JSON functions.
 *
 * Example value:
 * {
 *   "address1": "One Apple Park Way",
 *   "city": "Cupertino",
 *   "state": "CA",
 *   "postal_code": "95014"
 * }
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('tickers') && !Schema::hasColumn('tickers', 'address_json')) {
            Schema::table('tickers', function (Blueprint $table) {
                // JSON column for company address (optional, nullable)
                $table->json('address_json')
                      ->nullable()
                      ->after('branding_icon_url')
                      ->comment('Polygon.io structured address object');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tickers') && Schema::hasColumn('tickers', 'address_json')) {
            Schema::table('tickers', function (Blueprint $table) {
                $table->dropColumn('address_json');
            });
        }
    }
};