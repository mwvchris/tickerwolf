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
        // We’ll use a raw query to safely check existing indexes in MariaDB.
        $table = 'ticker_price_histories';
        $indexName = 'idx_ticker_latest';

        // Check if index already exists
        $indexExists = DB::select("
            SHOW INDEX FROM {$table} WHERE Key_name = ?
        ", [$indexName]);

        if (empty($indexExists)) {
            Schema::table($table, function (Blueprint $table) use ($indexName) {
                // Add compound index for latest prices per ticker
                // `t` is the date column
                $table->index(['ticker', 't'], $indexName);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_price_histories', function (Blueprint $table) {
            $table->dropIndex('idx_ticker_latest');
        });
    }
};