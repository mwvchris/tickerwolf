<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This table stores the full Polygon ticker overview payload (raw JSON)
     * and a few parsed/indexed fields for quick searches. It also supports
     * snapshots over time using the `overview_date` column (the date= query param).
     *
     * Use-case: keep full data for auditing, re-parsing, or supporting new UI features
     * without changing schema. Denormalized fields on `tickers` will be the
     * current/latest values used for profile pages.
     */
    public function up(): void
    {
        Schema::create('ticker_overviews', function (Blueprint $table) {
            $table->id();

            // Link back to tickers table
            $table->foreignId('ticker_id')->constrained('tickers')->cascadeOnDelete();

            // The `date` query param on Polygon endpoint — if null, this is "latest".
            $table->date('overview_date')->nullable()->index();

            // Frequently useful parsed fields indexed for queries
            $table->boolean('active')->nullable()->index();
            $table->unsignedBigInteger('market_cap')->nullable()->index();
            $table->string('primary_exchange')->nullable()->index();
            $table->string('locale')->nullable()->index();
            $table->string('status')->nullable();

            // Raw entire response stored for future-proofing.
            $table->json('results_raw')->nullable();

            $table->timestamp('fetched_at')->useCurrent(); // when we pulled the overview
            $table->timestamps();

            // Avoid duplicate snapshots for the same ticker + date.
            $table->unique(['ticker_id', 'overview_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticker_overviews');
    }
};