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
        Schema::create('ticker_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained('tickers')->cascadeOnDelete();
            $table->string('resolution', 8)->default('1d')->index();
            $table->timestamp('t')->index();
            $table->string('indicator')->index(); // e.g. sma_20, ema_50, rsi_14
            $table->decimal('value', 24, 8)->nullable();
            $table->json('meta')->nullable(); // For complex indicators (MACD, Bollinger)
            $table->unique(['ticker_id', 'resolution', 't', 'indicator']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_indicators');
    }
};