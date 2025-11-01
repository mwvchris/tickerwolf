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
            if (!Schema::hasColumn('ticker_fundamentals_financials', 'display_order')) {
                $table->integer('display_order')->nullable()->after('id')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
            if (Schema::hasColumn('ticker_fundamentals_financials', 'display_order')) {
                $table->dropColumn('display_order');
            }
        });
    }
};
