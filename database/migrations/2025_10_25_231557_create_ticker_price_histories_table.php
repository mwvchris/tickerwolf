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
        Schema::create('ticker_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained('tickers')->cascadeOnDelete();
            $table->string('ticker', 16)->index();
            $table->string('resolution', 8)->default('1d')->index(); // 1m, 5m, 15m, 1d, etc.
            $table->timestamp('t')->index(); // timestamp of bar (UTC)
            $table->decimal('o', 16, 6)->nullable();
            $table->decimal('h', 16, 6)->nullable();
            $table->decimal('l', 16, 6)->nullable();
            $table->decimal('c', 16, 6)->nullable();
            $table->bigInteger('v')->nullable();
            $table->json('raw')->nullable();
            $table->unique(['ticker_id', 'resolution', 't']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_price_histories');
    }
};