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
        Schema::table('ticker_price_histories', function (Blueprint $table) {
            $table->decimal('vw', 12, 6)->nullable()->after('v');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_price_histories', function (Blueprint $table) {
            $table->dropColumn('vw');
        });
    }
};
