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
        Schema::table('tickers', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name')->index();
            $table->unique(['ticker', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickers', function (Blueprint $table) {
            $table->dropUnique(['tickers_ticker_slug_unique']);
            $table->dropColumn('slug');
        });
    }
};