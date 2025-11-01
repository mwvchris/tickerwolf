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
        Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
            if (!Schema::hasColumn('ticker_fundamentals_financials', 'statement')) {
                $table->string('statement', 64)->nullable()->after('ticker');
            }
            if (!Schema::hasColumn('ticker_fundamentals_financials', 'line_item')) {
                $table->string('line_item', 128)->nullable()->after('statement');
            }
            if (!Schema::hasColumn('ticker_fundamentals_financials', 'label')) {
                $table->string('label')->nullable()->after('line_item');
            }
            if (!Schema::hasColumn('ticker_fundamentals_financials', 'unit')) {
                $table->string('unit')->nullable()->after('label');
            }
            if (!Schema::hasColumn('ticker_fundamentals_financials', 'display_order')) {
                $table->integer('display_order')->nullable()->after('unit');
            }
            if (!Schema::hasColumn('ticker_fundamentals_financials', 'value')) {
                $table->decimal('value', 24, 4)->nullable()->after('display_order');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_fundamentals_financials', function (Blueprint $table) {
            foreach (['statement','line_item','label','unit','display_order','value'] as $col) {
                if (Schema::hasColumn('ticker_fundamentals_financials', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
