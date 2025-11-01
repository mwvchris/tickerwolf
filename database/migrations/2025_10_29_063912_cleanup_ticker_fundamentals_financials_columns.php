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
        Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
            // Drop redundant high-level summary and JSON columns
            $dropColumns = [
                'assets', 'liabilities', 'equity', 'revenues', 'net_income',
                'gross_profit', 'operating_income', 'eps_basic', 'eps_diluted',
                'balance_sheet', 'income_statement', 'cash_flow_statement',
                'comprehensive_income', 'raw', 'fetched_at', 'filing_date',
                'start_date'
            ];

            foreach ($dropColumns as $col) {
                if (Schema::hasColumn('ticker_fundamentals_financials', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
            // Recreate dropped columns (basic structure only)
            $table->decimal('assets', 24, 2)->nullable();
            $table->decimal('liabilities', 24, 2)->nullable();
            $table->decimal('equity', 24, 2)->nullable();
            $table->decimal('revenues', 24, 2)->nullable();
            $table->decimal('net_income', 24, 2)->nullable();
            $table->decimal('gross_profit', 24, 2)->nullable();
            $table->decimal('operating_income', 24, 2)->nullable();
            $table->decimal('eps_basic', 16, 4)->nullable();
            $table->decimal('eps_diluted', 16, 4)->nullable();
            $table->longText('balance_sheet')->nullable();
            $table->longText('income_statement')->nullable();
            $table->longText('cash_flow_statement')->nullable();
            $table->longText('comprehensive_income')->nullable();
            $table->longText('raw')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->date('filing_date')->nullable();
            $table->date('start_date')->nullable();
        });
    }
};