<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
            // Add optimized indexes for high-speed analytics
            $table->index(['ticker_id', 'end_date'], 'tff_ticker_end_date_index');
            $table->index(['fiscal_year', 'fiscal_period'], 'tff_fiscal_index');
            $table->index(['statement', 'line_item'], 'tff_statement_line_item_index');
            $table->index(['ticker', 'statement'], 'tff_ticker_statement_index');
        });
    }

    public function down(): void
    {
        Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
            $table->dropIndex('tff_ticker_end_date_index');
            $table->dropIndex('tff_fiscal_index');
            $table->dropIndex('tff_statement_line_item_index');
            $table->dropIndex('tff_ticker_statement_index');
        });
    }
};