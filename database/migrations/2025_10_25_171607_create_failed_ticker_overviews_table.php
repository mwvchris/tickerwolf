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
        Schema::create('failed_ticker_overviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticker_id')->constrained()->cascadeOnDelete();
            $table->string('ticker')->index();
            $table->string('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
    * Reverse the migrations.
    */
    public function down(): void
    {
        Schema::dropIfExists('failed_ticker_overviews');
    }
};