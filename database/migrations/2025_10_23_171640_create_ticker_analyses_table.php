<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void {
        Schema::create('ticker_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('ticker')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider')->index(); // openai, gemini, grok, etc.
            $table->string('model')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_raw')->nullable();
            $table->text('summary')->nullable();
            $table->json('structured')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('ticker_analyses');
    }
};
