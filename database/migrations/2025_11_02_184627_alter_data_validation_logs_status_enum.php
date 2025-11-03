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
        Schema::table('data_validation_logs', function (Blueprint $table) {
            $table->enum('status', ['running', 'success', 'warning', 'error'])
                ->default('running')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_validation_logs', function (Blueprint $table) {
            $table->enum('status', ['success', 'warning', 'error'])
                ->default('success')
                ->change();
        });
    }
};