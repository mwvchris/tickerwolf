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
            $table->unsignedBigInteger('total_entities')->nullable()->change();
            $table->unsignedBigInteger('validated_count')->nullable()->change();
            $table->unsignedBigInteger('missing_count')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_validation_logs', function (Blueprint $table) {
            $table->unsignedInteger('total_entities')->nullable()->change();
            $table->unsignedInteger('validated_count')->nullable()->change();
            $table->unsignedInteger('missing_count')->nullable()->change();
        });
    }
};