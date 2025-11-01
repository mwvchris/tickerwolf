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
        Schema::create('tickers', function (Blueprint $table) {
            $table->id();

            // Primary / searchable fields
            $table->string('ticker')->unique();                   // ticker symbol e.g., AAPL
            $table->string('name')->nullable();                   // company name
            $table->string('market')->nullable();                 // 'stocks', 'crypto', ...
            $table->string('locale')->nullable();                 // 'us' / 'global'
            $table->string('primary_exchange')->nullable();
            $table->string('type')->nullable();                   // human readable type
            $table->string('status')->nullable();                 // e.g., 'active'
            $table->boolean('active')->nullable()->index();
            $table->string('currency_symbol')->nullable();
            $table->string('currency_name')->nullable();
            $table->string('base_currency_symbol')->nullable();
            $table->string('base_currency_name')->nullable();
            $table->string('cik')->nullable()->index();
            $table->string('composite_figi')->nullable();
            $table->string('share_class_figi')->nullable();
            $table->timestamp('last_updated_utc')->nullable()->index();
            $table->timestamp('delisted_utc')->nullable()->index();

            // Store raw payload for future fields, faster schema changes
            $table->json('raw')->nullable();

            $table->timestamps();

            // Useful compound query
            $table->index(['market', 'primary_exchange']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickers');
    }
};
