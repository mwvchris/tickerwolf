<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `ticker_feature_snapshots` table, which stores a per-ticker,
     * per-date JSON blob of all computed indicators and derived analytics.
     *
     * This structure serves as a compact, AI-ready "feature vector" format
     * — one record per ticker per day — optimized for machine learning and
     * market modeling pipelines.
     */
    public function up(): void
    {
        Schema::create('ticker_feature_snapshots', function (Blueprint $table) {
            $table->id();

            // The ticker symbol reference
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();

            // Timestamp (typically 1D resolution)
            $table->date('t')->index();

            // All computed indicators and analytics as JSON
            $table->json('indicators');

            // Optional embedding vector or summary fields for LLM ingestion
            $table->json('embedding')->nullable();

            $table->timestamps();

            // Each ticker_id + date combination must be unique
            $table->unique(['ticker_id', 't']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_feature_snapshots');
    }
};