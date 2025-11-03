<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_batches', function (Blueprint $table) {
            // Change `id` to UUID primary key (if not already)
            if (Schema::hasColumn('job_batches', 'id')) {
                DB::statement('ALTER TABLE job_batches MODIFY id CHAR(36) NOT NULL');
            } else {
                $table->uuid('id')->primary();
            }

            // Add processed_jobs if missing
            if (! Schema::hasColumn('job_batches', 'processed_jobs')) {
                $table->unsignedInteger('processed_jobs')->default(0)->after('failed_jobs');
            }

            // Add status column if missing
            if (! Schema::hasColumn('job_batches', 'status')) {
                $table->string('status', 20)->default('running')->after('processed_jobs');
            }
        });
    }

    public function down(): void
    {
        // revert optional
    }
};