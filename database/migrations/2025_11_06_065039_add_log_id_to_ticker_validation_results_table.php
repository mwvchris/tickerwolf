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
        Schema::table('ticker_validation_results', function (Blueprint $table) {
            $table->foreignId('log_id')
                ->nullable()
                ->after('id')
                ->comment('References data_validation_logs.id for run-level context')
                ->constrained('data_validation_logs')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_validation_results', function (Blueprint $table) {
            $table->dropForeign(['log_id']);
            $table->dropColumn('log_id');
        });
    }
};