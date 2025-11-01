<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ============================================================================
 *  CreateDataValidationLogsTable
 * ============================================================================
 *
 * Purpose:
 *   Persistent audit log for data validation runs across TickerWolf.ai subsystems.
 *   Records metadata, counts, and anomaly summaries for each validation event.
 *
 *   This table is intentionally generic — the "entity_type" column distinguishes
 *   between ticker-level, sentiment-level, or other data validations.
 *
 * ============================================================================
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_validation_logs', function (Blueprint $table) {
            $table->id();

            // Generic categorization fields
            $table->string('entity_type', 50)->default('ticker')
                  ->comment('The logical entity being validated (e.g. ticker, sentiment, fundamentals)');
            $table->string('command_name', 100)
                  ->comment('The artisan command or script that triggered this validation');

            // Counts and summary metrics
            $table->unsignedInteger('total_entities')->nullable()
                  ->comment('Total count of expected records');
            $table->unsignedInteger('validated_count')->nullable()
                  ->comment('Number of entities successfully validated');
            $table->unsignedInteger('missing_count')->nullable()
                  ->comment('Number of missing or invalid entities');

            // JSON summary blob for flexible anomaly detail storage
            $table->json('details')->nullable()
                  ->comment('JSON-encoded summary of anomalies, missing tables, etc.');

            // Status & timing
            $table->enum('status', ['success', 'warning', 'error'])
                  ->default('success')
                  ->comment('Overall validation outcome');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Traceability
            $table->string('initiated_by', 100)->nullable()
                  ->comment('User or system actor initiating validation');
            $table->timestamps();
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('data_validation_logs');
    }
};