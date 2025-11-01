<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticker_fundamentals_financials', function (Blueprint $table) {
            $table->id();

            // Foreign key to tickers
            $table->foreignId('ticker_id')->nullable()->index();
            $table->foreign('ticker_id', 'fk_tfundamentals_financials_ticker_id')
                  ->references('id')
                  ->on('tickers')
                  ->nullOnDelete();

            // Core identifying fields
            $table->string('ticker', 16)->index();
            $table->string('fiscal_period', 8)->nullable()->index(); // Q1, Q2, FY, etc.
            $table->string('fiscal_year', 8)->nullable()->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('filing_date')->nullable();

            // Financial summary metrics
            $table->decimal('assets', 24, 2)->nullable();
            $table->decimal('liabilities', 24, 2)->nullable();
            $table->decimal('equity', 24, 2)->nullable();
            $table->decimal('revenues', 24, 2)->nullable();
            $table->decimal('net_income', 24, 2)->nullable();
            $table->decimal('gross_profit', 24, 2)->nullable();
            $table->decimal('operating_income', 24, 2)->nullable();
            $table->decimal('eps_basic', 16, 4)->nullable();
            $table->decimal('eps_diluted', 16, 4)->nullable();

            // Full JSON statements for detailed analytics
            $table->json('balance_sheet')->nullable();
            $table->json('income_statement')->nullable();
            $table->json('cash_flow_statement')->nullable();
            $table->json('comprehensive_income')->nullable();

            // Raw payload for traceability
            $table->json('raw')->nullable();

            // Timestamp from Polygon response and local ingestion tracking
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_fundamentals_financials');
    }
};