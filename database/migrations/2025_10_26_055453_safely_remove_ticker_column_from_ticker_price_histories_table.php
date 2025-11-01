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
        // First, drop all indexes that reference the `ticker` column
        $indexes = [
            'unique_ticker_t_resolution_year',
            'ticker_t_index',
            'ticker_resolution_index',
            'ticker_price_histories_ticker_index',
            'idx_ticker_latest',
        ];

        foreach ($indexes as $index) {
            try {
                DB::statement("ALTER TABLE ticker_price_histories DROP INDEX `$index`");
            } catch (\Throwable $e) {
                // Ignore if index doesn't exist
            }
        }

        // Finally, drop the ticker column if it exists
        Schema::table('ticker_price_histories', function (Blueprint $table) {
            if (Schema::hasColumn('ticker_price_histories', 'ticker')) {
                $table->dropColumn('ticker');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_price_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('ticker_price_histories', 'ticker')) {
                $table->string('ticker', 16)->nullable()->after('ticker_id');
            }

            // Optionally restore one index if needed
            $table->index('ticker');
        });
    }
};