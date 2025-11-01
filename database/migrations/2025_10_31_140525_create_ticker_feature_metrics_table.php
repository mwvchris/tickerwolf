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
        Schema::create('ticker_feature_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained('tickers')->onDelete('cascade');
            $table->date('t')->index();

            $table->double('sharpe_60')->nullable();
            $table->double('volatility_30')->nullable();
            $table->double('drawdown')->nullable();
            $table->double('beta_60')->nullable();
            $table->double('momentum_10')->nullable();

            $table->timestamps();

            $table->unique(['ticker_id', 't']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_feature_metrics');
    }
};
