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
        Schema::table('ticker_news_items', function (Blueprint $table) {
            // --- Rename existing column for consistency ---
            if (Schema::hasColumn('ticker_news_items', 'source')) {
                $table->renameColumn('source', 'publisher_name');
            }

            // --- New structured Polygon.io fields ---
            $table->string('article_id', 128)->nullable()->after('id')->index();
            $table->string('author', 255)->nullable()->after('publisher_name');
            $table->string('image_url', 512)->nullable()->after('summary');
            $table->string('amp_url', 512)->nullable()->after('image_url');

            // Publisher details (beyond name)
            $table->string('publisher_logo_url', 512)->nullable()->after('author');
            $table->string('publisher_favicon_url', 512)->nullable()->after('publisher_logo_url');
            $table->string('publisher_homepage_url', 512)->nullable()->after('publisher_favicon_url');

            // Related tickers, keywords, and insights
            $table->json('tickers_list')->nullable()->after('publisher_homepage_url');
            $table->json('keywords')->nullable()->after('tickers_list');
            $table->json('insights')->nullable()->after('keywords');

            // Flattened insight fields for direct filtering
            $table->string('insight_sentiment', 32)->nullable()->after('insights');
            $table->text('insight_reasoning')->nullable()->after('insight_sentiment');
            $table->string('insight_ticker', 16)->nullable()->after('insight_reasoning');

            // Rename published_at → published_utc for consistency
            if (Schema::hasColumn('ticker_news_items', 'published_at')) {
                $table->renameColumn('published_at', 'published_utc');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticker_news_items', function (Blueprint $table) {
            // Drop new fields
            $table->dropColumn([
                'article_id',
                'author',
                'image_url',
                'amp_url',
                'publisher_logo_url',
                'publisher_favicon_url',
                'publisher_homepage_url',
                'tickers_list',
                'keywords',
                'insights',
                'insight_sentiment',
                'insight_reasoning',
                'insight_ticker',
            ]);

            // Rename back to original
            if (Schema::hasColumn('ticker_news_items', 'publisher_name')) {
                $table->renameColumn('publisher_name', 'source');
            }

            if (Schema::hasColumn('ticker_news_items', 'published_utc')) {
                $table->renameColumn('published_utc', 'published_at');
            }
        });
    }
};