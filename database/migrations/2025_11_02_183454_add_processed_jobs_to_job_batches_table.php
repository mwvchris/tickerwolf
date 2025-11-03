<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the processed_jobs column to the job_batches table.
     *
     * This column was introduced in Laravel 10.8+ for more precise
     * batch progress tracking and to allow real-time % complete reporting.
     */
    public function up(): void
    {
        Schema::table('job_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('job_batches', 'processed_jobs')) {
                $table->unsignedInteger('processed_jobs')->default(0)->after('failed_jobs');
            }
        });
    }

    /**
     * Reverse the migration changes.
     */
    public function down(): void
    {
        Schema::table('job_batches', function (Blueprint $table) {
            if (Schema::hasColumn('job_batches', 'processed_jobs')) {
                $table->dropColumn('processed_jobs');
            }
        });
    }
};