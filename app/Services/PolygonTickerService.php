<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Handles ingestion of all tickers from the Polygon.io API.
 * Supports automatic pagination, rate limit backoff, and resumable polling.
 */
class PolygonTickerService
{
    protected PolygonApiClient $client;

    // Default polling interval (seconds)
    protected int $pollInterval = 10;

    public function __construct(PolygonApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Ingest all tickers via Polygon reference endpoint.
     *
     * @param  array  $options  Additional query params (e.g. ['market' => 'stocks'])
     * @param  bool   $poll     Whether to continuously poll for updates
     */
    public function ingestAll(array $options = [], bool $poll = false): array
    {
        do {
            $stats = $this->runIngestionPass($options);

            Log::info('Polygon ticker ingestion completed', [
                'pages' => $stats['pages'],
                'inserted' => $stats['inserted'],
                'timestamp' => now()->toDateTimeString(),
            ]);

            if ($poll) {
                Log::info("Sleeping {$this->pollInterval}s before next polling pass...");
                sleep($this->pollInterval);
            }

        } while ($poll);

        return $stats;
    }

    /**
     * Executes a single ingestion run (all pages).
     */
    protected function runIngestionPass(array $options = []): array
    {
        $endpoint = '/v3/reference/tickers';
        $params = array_merge(['limit' => 1000], $options);

        $nextUrl = $endpoint . '?' . http_build_query($params);
        $pages = 0;
        $totalInserted = 0;

        while ($nextUrl) {
            $pages++;
            try {
                $response = $this->client->get($nextUrl);

                if (! $response->ok()) {
                    Log::error('Polygon ticker ingestion failed', [
                        'url' => $nextUrl,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $json = $response->json();
                $results = $json['results'] ?? [];

                if (!empty($results)) {
                    [$inserted] = $this->persistTickerResults($results);
                    $totalInserted += $inserted;
                }

                // Determine next page
                $nextUrl = $json['next_url'] ?? null;
                if ($nextUrl) {
                    $nextUrl = $this->appendApiKey($nextUrl);
                    sleep(1); // gentle pause between pages
                }

            } catch (Throwable $e) {
                Log::error('Polygon ticker ingestion exception', [
                    'url' => $nextUrl,
                    'message' => $e->getMessage(),
                ]);

                // Retry logic for transient issues (MySQL disconnects, network hiccups, etc.)
                sleep(5);
                continue;
            }

            // Basic rate limit pacing (Polygon's free tier = ~5 req/s)
            sleep(2);
        }

        return [
            'inserted' => $totalInserted,
            'pages' => $pages,
        ];
    }

    /**
     * Append the Polygon API key to a URL if missing.
     */
    protected function appendApiKey(string $url): string
    {
        return str_contains($url, 'apiKey=')
            ? $url
            : $url . (str_contains($url, '?') ? '&' : '?') . 'apiKey=' . urlencode(env('POLYGON_API_KEY'));
    }

    /**
     * Upsert ticker rows into the database.
     */
    protected function persistTickerResults(array $results): array
    {
        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($results as $item) {
            $rows[] = [
                'ticker' => $item['ticker'] ?? null,
                'name' => $item['name'] ?? null,
                'market' => $item['market'] ?? null,
                'locale' => $item['locale'] ?? null,
                'primary_exchange' => $item['primary_exchange'] ?? null,
                'type' => $item['type'] ?? null,
                'status' => $item['status'] ?? null,
                'active' => $item['active'] ?? null,
                'currency_symbol' => $item['currency_symbol'] ?? null,
                'currency_name' => $item['currency_name'] ?? null,
                'composite_figi' => $item['composite_figi'] ?? null,
                'share_class_figi' => $item['share_class_figi'] ?? null,
                'last_updated_utc' => isset($item['last_updated_utc'])
                    ? Carbon::parse($item['last_updated_utc'])->toDateTimeString()
                    : null,
                'delisted_utc' => isset($item['delisted_utc'])
                    ? Carbon::parse($item['delisted_utc'])->toDateTimeString()
                    : null,
                'raw' => json_encode($item),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($rows)) {
            DB::table('tickers')->upsert(
                $rows,
                ['ticker'],
                [
                    'name', 'market', 'locale', 'primary_exchange',
                    'type', 'status', 'active', 'currency_symbol',
                    'currency_name', 'composite_figi', 'share_class_figi',
                    'last_updated_utc', 'delisted_utc', 'raw', 'updated_at',
                ]
            );
        }

        return [count($rows)];
    }
}