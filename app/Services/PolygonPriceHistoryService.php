<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;

class PolygonPriceHistoryService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $maxRetries;
    protected string $logChannel;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.polygon.base') ?? env('POLYGON_API_BASE', 'https://api.polygon.io'), '/');
        $this->apiKey = config('services.polygon.key') ?? env('POLYGON_API_KEY');
        $this->timeout = (int)(env('POLYGON_API_TIMEOUT', 30));
        $this->maxRetries = (int)(env('POLYGON_API_RETRIES', 3));
        $this->logChannel = 'polygon';
    }

    public function fetchAggregates(string $symbol, int $multiplier, string $timespan, string $from, string $to, array $extraParams = []): array
    {
        $endpoint = "/v2/aggs/ticker/{$symbol}/range/{$multiplier}/{$timespan}/{$from}/{$to}";
        $params = array_merge(['apiKey' => $this->apiKey, 'limit' => 50000], $extraParams);
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        $attempt = 0;
        $wait = 1;

        while (true) {
            $attempt++;
            try {
                $resp = Http::timeout($this->timeout)->get($url);

                if ($resp->successful()) {
                    $json = $resp->json();
                    Log::channel($this->logChannel)->debug("Polygon raw response for {$symbol} ({$from}→{$to}): " . json_encode($json));
                    return $json['results'] ?? [];
                }

                if ($resp->status() == 429) {
                    $retryAfter = $resp->header('Retry-After') ?? $wait;
                    Log::channel($this->logChannel)->warning("Polygon 429 for {$symbol}. Retry after {$retryAfter}s (attempt {$attempt})");
                    sleep((int)$retryAfter);
                } elseif ($resp->serverError()) {
                    if ($attempt >= $this->maxRetries) {
                        Log::channel($this->logChannel)->error("Polygon server error for {$symbol} after {$attempt} attempts. Status: {$resp->status()}");
                        break;
                    }
                    sleep($wait);
                    $wait *= 2;
                } else {
                    Log::channel($this->logChannel)->warning("Polygon client error for {$symbol}: status {$resp->status()} body: {$resp->body()}");
                    return [];
                }
            } catch (Throwable $e) {
                Log::channel($this->logChannel)->error("HTTP error fetching aggregates for {$symbol}: {$e->getMessage()}");
                if ($attempt >= $this->maxRetries) break;
                sleep($wait);
                $wait *= 2;
            }

            if ($attempt >= ($this->maxRetries + 3)) break;
        }

        return [];
    }

    public function upsertBars(int $tickerId, string $symbol, string $resolution, array $bars): int
    {
        if (empty($bars)) return 0;

        $now = Carbon::now()->toDateTimeString();
        $rows = [];

        foreach ($bars as $b) {
            $ts = isset($b['t']) ? Carbon::createFromTimestampMs((int)$b['t']) : null;
            if (!$ts) continue;

            $rows[] = [
                'ticker_id' => $tickerId,
                'resolution' => $resolution,
                't' => $ts->toDateTimeString(),
                'year' => (int)$ts->year,
                'o' => $b['o'] ?? null,
                'h' => $b['h'] ?? null,
                'l' => $b['l'] ?? null,
                'c' => $b['c'] ?? null,
                'v' => isset($b['v']) ? (int)$b['v'] : null,
                'vw' => $b['vw'] ?? null,
                'raw' => json_encode($b),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) return 0;

        DB::table('ticker_price_histories')->upsert(
            $rows,
            ['ticker_id', 'resolution', 't'],
            ['o', 'h', 'l', 'c', 'v', 'vw', 'raw', 'updated_at']
        );

        return count($rows);
    }
}