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
        Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
            if (!Schema::hasColumn('ticker_fundamentals_financials', 'fundamental_id')) {
                $table->unsignedBigInteger('fundamental_id')->nullable()->after('id');

                // Add foreign key if parent table exists
                if (Schema::hasTable('ticker_fundamentals')) {
                    $table->foreign('fundamental_id')
                          ->references('id')
                          ->on('ticker_fundamentals')
                          ->onDelete('cascade');
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
            if (Schema::hasColumn('ticker_fundamentals_financials', 'fundamental_id')) {
                $table->dropForeign(['fundamental_id']);
                $table->dropColumn('fundamental_id');
            }
        });
    }
};