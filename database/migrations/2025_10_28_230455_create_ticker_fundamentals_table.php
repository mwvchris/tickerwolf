<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticker_fundamentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->nullable()->index();
            $table->foreign('ticker_id', 'fk_tfundamentals_ticker_id')->references('id')->on('tickers')->nullOnDelete();
            $table->string('ticker', 16)->index();
            $table->string('cik', 32)->nullable()->index();
            $table->string('company_name')->nullable();

            $table->string('fiscal_period', 8)->nullable()->index(); // e.g., Q1, Q2, FY
            $table->string('fiscal_year', 8)->nullable()->index();
            $table->string('timeframe', 16)->nullable()->index(); // e.g., quarterly, annual
            $table->string('status', 16)->nullable()->index();

            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable()->index();
            $table->date('filing_date')->nullable()->index();

            $table->string('source_filing_url', 512)->nullable();
            $table->string('source_filing_file_url', 512)->nullable();

            // Core top-level metrics for quick access
            $table->decimal('total_assets', 24, 2)->nullable();
            $table->decimal('total_liabilities', 24, 2)->nullable();
            $table->decimal('equity', 24, 2)->nullable();
            $table->decimal('net_income', 24, 2)->nullable();
            $table->decimal('revenue', 24, 2)->nullable();
            $table->decimal('operating_income', 24, 2)->nullable();
            $table->decimal('gross_profit', 24, 2)->nullable();
            $table->decimal('eps_basic', 16, 4)->nullable();
            $table->decimal('eps_diluted', 16, 4)->nullable();

            // Raw JSON blobs
            $table->json('balance_sheet')->nullable();
            $table->json('income_statement')->nullable();
            $table->json('cash_flow_statement')->nullable();
            $table->json('comprehensive_income')->nullable();
            $table->json('raw')->nullable();

            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['ticker_id', 'fiscal_year', 'fiscal_period'], 'uq_tfundamentals_unique_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_fundamentals');
    }
};
