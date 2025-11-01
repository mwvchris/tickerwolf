<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ticker_fundamentals', function (Blueprint $table) {
            $indexName = 'uq_tfundamentals_unique_period';
            
            // Check existing indexes safely
            $existingIndexes = collect(DB::select('SHOW INDEX FROM ticker_fundamentals'))
                ->pluck('Key_name');

            if (!$existingIndexes->contains($indexName)) {
                $table->unique(
                    ['ticker_id', 'fiscal_year', 'fiscal_period'],
                    $indexName
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_fundamentals', function (Blueprint $table) {
            if (Schema::hasTable('ticker_fundamentals')) {
                $table->dropUnique('uq_tfundamentals_unique_period');
            }
        });
    }
};