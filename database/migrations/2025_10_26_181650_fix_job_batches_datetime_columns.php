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
            // Convert integer timestamp columns to proper datetime
            $table->dateTime('created_at')->nullable()->change();
            $table->dateTime('finished_at')->nullable()->change();
            $table->dateTime('cancelled_at')->nullable()->change();
        });
    }

    
    public function down(): void
    {
        Schema::table('job_batches', function (Blueprint $table) {
            // Rollback to integer timestamps if needed
            $table->bigInteger('created_at')->nullable()->change();
            $table->bigInteger('finished_at')->nullable()->change();
            $table->bigInteger('cancelled_at')->nullable()->change();
        });
    }
};