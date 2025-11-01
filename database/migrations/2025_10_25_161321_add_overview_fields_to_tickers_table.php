<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds frequently-used ticker overview fields directly on the `tickers`
     * table to keep ticker profile page queries fast (avoid joins on each load).
     *
     * These fields are denormalized from Polygon's /v3/reference/tickers/{ticker}
     * endpoint. Less-frequently used or arbitrary fields will still be stored
     * in the `ticker_overviews` table (raw JSON).
     */
    public function up(): void
    {
        Schema::table('tickers', function (Blueprint $table) {
            // Overview / company info
            $table->text('description')->nullable()->after('name'); // company description
            $table->string('homepage_url')->nullable()->after('description');
            $table->date('list_date')->nullable()->after('homepage_url'); // YYYY-MM-DD
            $table->unsignedBigInteger('market_cap')->nullable()->after('list_date')->index();

            // Contact / size
            $table->string('phone_number')->nullable()->after('market_cap');
            $table->integer('total_employees')->nullable()->after('phone_number')->index();

            // Industry / classification
            $table->string('sic_code')->nullable()->after('total_employees')->index();
            $table->string('sic_description')->nullable()->after('sic_code');

            // Share class / shares outstanding
            $table->unsignedBigInteger('share_class_shares_outstanding')->nullable()->after('sic_description');
            $table->unsignedBigInteger('weighted_shares_outstanding')->nullable()->after('share_class_shares_outstanding');

            // Ticker components
            $table->string('ticker_root')->nullable()->after('weighted_shares_outstanding')->index();
            $table->string('ticker_suffix')->nullable()->after('ticker_root');

            // Round lot and other numeric small fields
            $table->integer('round_lot')->nullable()->after('ticker_suffix');

            // Small branding fields to display quickly without parsing JSON
            $table->string('branding_logo_url')->nullable()->after('round_lot');
            $table->string('branding_icon_url')->nullable()->after('branding_logo_url');

            // keep the raw overview on the tickers table for quick access if needed,
            // but we'll also store full historical snapshots in ticker_overviews.
            // If your existing 'raw' column is used for other payloads, leave as-is.
            // $table->json('overview_raw')->nullable()->after('raw'); // optional
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickers', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'homepage_url',
                'list_date',
                'market_cap',
                'phone_number',
                'total_employees',
                'sic_code',
                'sic_description',
                'share_class_shares_outstanding',
                'weighted_shares_outstanding',
                'ticker_root',
                'ticker_suffix',
                'round_lot',
                'branding_logo_url',
                'branding_icon_url',
            ]);
        });
    }
};