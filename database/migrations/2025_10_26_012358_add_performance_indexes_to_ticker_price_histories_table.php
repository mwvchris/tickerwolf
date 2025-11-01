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
        if (!Schema::hasTable('ticker_price_histories')) {
            return;
        }

        Schema::table('ticker_price_histories', function (Blueprint $table) {
            // Add performance indexes (if not already present)
            $table->index(['ticker', 't'], 'ticker_t_index');
            $table->index(['ticker', 'resolution'], 'ticker_resolution_index');
            $table->index('t', 't_index');
            $table->unique(['ticker', 't', 'resolution'], 'unique_ticker_t_resolution');
        });

        // Optional but highly useful: create a materialized-style view for the latest price per ticker
        DB::statement("
            CREATE OR REPLACE VIEW latest_ticker_prices AS
            SELECT tph.*
            FROM ticker_price_histories tph
            INNER JOIN (
                SELECT ticker, MAX(t) AS latest_t
                FROM ticker_price_histories
                GROUP BY ticker
            ) latest
            ON tph.ticker = latest.ticker AND tph.t = latest.latest_t
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('ticker_price_histories')) {
            return;
        }

        Schema::table('ticker_price_histories', function (Blueprint $table) {
            $table->dropIndex('ticker_t_index');
            $table->dropIndex('ticker_resolution_index');
            $table->dropIndex('t_index');
            $table->dropUnique('unique_ticker_t_resolution');
        });

        DB::statement('DROP VIEW IF EXISTS latest_ticker_prices');
    }
};