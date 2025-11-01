<?php

namespace App\Services;

use App\Models\Ticker;
use App\Models\TickerNewsItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

class PolygonTickerNewsService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = (string) config('services.polygon.key');
        $this->baseUrl = 'https://api.polygon.io/v2/reference/news';
    }

    /**
     * Fetch and store news for a single ticker.
     */
    public function fetchNewsForTicker(string $ticker, int $limit = 50): int
    {
        $symbol = strtoupper(trim($ticker));
        $url    = "{$this->baseUrl}?ticker={$symbol}&limit={$limit}&apiKey={$this->apiKey}";
        $resp   = Http::timeout(30)->get($url);

        if (! $resp->ok()) {
            Log::channel('ingest')->error("Polygon News API failed for {$symbol}", [
                'status' => $resp->status(),
                'body'   => $resp->body(),
            ]);
            return 0;
        }

        $json    = $resp->json() ?? [];
        $results = $json['results'] ?? [];

        if (!is_array($results) || empty($results)) {
            return 0;
        }

        $count = 0;
        foreach ($results as $item) {
            $count += $this->storeNewsItem($symbol, (array) $item);
        }

        Log::channel('ingest')->info("📰 Ingested {$count} news items for {$symbol}");
        return $count;
    }

    /**
     * Store a single Polygon.io news item.
     */
    protected function storeNewsItem(string $symbol, array $item): int
    {
        try {
            $tickerModel = Ticker::where('ticker', $symbol)->first();

            // Flatten one insight if present
            $insight = is_array($item['insights'] ?? null) && count($item['insights']) > 0
                ? (array) $item['insights'][0]
                : null;

            // Build a deterministic unique article_id per ticker
            $articleId = $item['id'] ?? null;
            $hashSource = ($symbol ?? '') . '|' .
                          ($item['article_url'] ?? '') . '|' .
                          ($item['published_utc'] ?? '') . '|' .
                          ($item['title'] ?? '') . '|' .
                          ($item['author'] ?? '');
            $articleId = $articleId ? "{$symbol}_{$articleId}" : md5($hashSource);

            $attributes = [
                'article_id' => $articleId,
            ];

            $values = [
                'ticker_id'              => $tickerModel?->id,
                'ticker'                 => $symbol,

                // Author / Publisher
                'author'                 => $item['author'] ?? null,
                'publisher_name'         => Arr::get($item, 'publisher.name'),
                'publisher_logo_url'     => Arr::get($item, 'publisher.logo_url'),
                'publisher_favicon_url'  => Arr::get($item, 'publisher.favicon_url'),
                'publisher_homepage_url' => Arr::get($item, 'publisher.homepage_url'),

                // Core content
                'title'                  => $item['title'] ?? null,
                'summary'                => $item['description'] ?? null,
                'url'                    => $item['article_url'] ?? null,
                'amp_url'                => $item['amp_url'] ?? null,
                'image_url'              => $item['image_url'] ?? null,

                // Arrays (cast to array in model)
                'tickers_list'           => isset($item['tickers'])  ? array_values((array) $item['tickers'])  : null,
                'keywords'               => isset($item['keywords']) ? array_values((array) $item['keywords']) : null,
                'insights'               => isset($item['insights']) ? (array) $item['insights']               : null,

                // Flattened insight fields
                'insight_sentiment'      => $insight['sentiment'] ?? null,
                'insight_reasoning'      => $insight['sentiment_reasoning'] ?? null,
                'insight_ticker'         => $insight['ticker'] ?? null,

                // Publish time
                'published_utc'          => isset($item['published_utc'])
                    ? date('Y-m-d H:i:s', strtotime($item['published_utc']))
                    : null,

                // Store raw payload
                'raw'                    => $item,
                'updated_at'             => now(),
                'created_at'             => now(),
            ];

            TickerNewsItem::updateOrCreate($attributes, $values);

            return 1;
        } catch (\Throwable $e) {
            Log::channel('ingest')->error("❌ Failed to store news for {$symbol}: {$e->getMessage()}", [
                'item' => $item,
            ]);
            return 0;
        }
    }

    /**
     * Ingest for all active tickers (simple sequential mode).
     */
    public function ingestAllTickers(int $limit = 50): void
    {
        Ticker::where('active', true)
            ->orderBy('id')
            ->pluck('ticker')
            ->each(function ($sym) use ($limit) {
                $this->fetchNewsForTicker($sym, $limit);
                usleep(400_000); // 0.4s pacing
            });
    }
}