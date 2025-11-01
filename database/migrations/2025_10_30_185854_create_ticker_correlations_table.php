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
        Schema::create('ticker_correlations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticker_id_a'); // e.g., AAPL
            $table->unsignedBigInteger('ticker_id_b'); // e.g., NVDA
            $table->date('as_of_date');                 // end of rolling window
            $table->float('corr', 8, 5)->nullable();    // correlation coefficient
            $table->float('beta', 8, 5)->nullable();    // optional regression slope
            $table->float('r2', 8, 5)->nullable();      // optional R²
            $table->timestamps();

            $table->unique(['ticker_id_a', 'ticker_id_b', 'as_of_date']);
            $table->index(['ticker_id_a', 'as_of_date']);
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('ticker_correlations');
    }
};
