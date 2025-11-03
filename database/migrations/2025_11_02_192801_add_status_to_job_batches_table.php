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
        Schema::table('job_batches', function (Blueprint $table) {
            // Add new status column with sensible default
            if (! Schema::hasColumn('job_batches', 'status')) {
                $table->string('status', 20)->default('running')->after('processed_jobs');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_batches', function (Blueprint $table) {
            if (Schema::hasColumn('job_batches', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
