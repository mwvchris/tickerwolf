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
        Schema::create('ticker_news_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->nullable()->constrained('tickers')->nullOnDelete()->index();
            $table->string('ticker', 16)->nullable()->index();
            $table->string('source')->nullable();
            $table->string('url')->nullable();
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_news_items');
    }
};