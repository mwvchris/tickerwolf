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
        Schema::create('ticker_validation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')
                ->constrained('tickers')
                ->onDelete('cascade');
            $table->string('validator', 50)
                ->comment('Validator name: price_history, indicators, metrics, snapshots, etc.');
            $table->enum('status', ['success', 'warning', 'error', 'insufficient', 'exception'])
                ->default('success');
            $table->decimal('health', 5, 3)->nullable()
                ->comment('Aggregate health score 0–1');
            $table->json('issues')->nullable()
                ->comment('JSON list or object of validation anomalies');
            $table->timestamp('validated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['ticker_id', 'validator'], 'uq_tvr_ticker_validator');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_validation_results');
    }
};
