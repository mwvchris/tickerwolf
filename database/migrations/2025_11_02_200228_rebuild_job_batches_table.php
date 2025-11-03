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
        Schema::dropIfExists('job_batches');

        Schema::create('job_batches', function (Blueprint $table) {
            // Core identifiers
            $table->string('id')->primary();
            $table->string('name');

            // Job tracking
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->integer('processed_jobs')->default(0);

            // Failure + options metadata
            $table->longText('failed_job_ids')->nullable()->default('[]');
            $table->mediumText('options')->nullable();

            // Batch lifecycle status
            $table->string('status')->default('running');

            // 🚀 Laravel Bus uses 32-bit UNIX timestamps for these columns.
            // Using plain integer avoids truncation / invalid datetime warnings.
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();

            // Optional high-resolution tracking for your app (Eloquent safe)
            $table->timestamp('updated_at')->nullable();

            // Helpful indexes
            $table->index('created_at');
            $table->index('finished_at');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};