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
        if (Schema::hasTable('ticker_indicators')) {
            Schema::table('ticker_indicators', function (Blueprint $table) {
                // Speeds: WHERE ticker_id=? AND indicator=? AND t BETWEEN ?
                $table->index(['ticker_id', 'indicator', 't'], 'idx_ticker_indicator_t');
            });
        }
    }

    
    public function down(): void
    {
        if (Schema::hasTable('ticker_indicators')) {
            Schema::table('ticker_indicators', function (Blueprint $table) {
                $table->dropIndex('idx_ticker_indicator_t');
            });
        }
    }
};