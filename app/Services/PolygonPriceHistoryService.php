<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Service: PolygonPriceHistoryService
 * -----------------------------------
 * Responsible for retrieving and persisting historical OHLCV bar data
 * from the Polygon.io Aggregates API (/v2/aggs/ticker/...).
 *
 * This service is used by higher-level ingestion services to:
 *  - Fetch remote daily bars (open/high/low/close/volume)
 *  - Handle retry logic, throttling, and server exceptions
 *  - Upsert resulting bars into ticker_price_histories efficiently
 *
 * Logging verbosity is intentionally high to enable granular debugging
 * of ingestion, response counts, and API behavior differences across
 * instrument types (CS, PFD, UNIT, ETF, etc.).
 *
 * 🚀 v2.8.0 — Added support for explicit `ticker` field upsert
 *             to ensure non-null constraint satisfaction.
 *             Retains v2.7.0 sanitization and safety features.
 */
class PolygonPriceHistoryService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $maxRetries;
    protected string $logChannel;

    /**
     * Constructor: Initializes configuration and HTTP defaults.
     */
    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.polygon.base') ?? env('POLYGON_API_BASE', 'https://api.polygon.io'), '/');
        $this->apiKey = config('services.polygon.key') ?? env('POLYGON_API_KEY');
        $this->timeout = (int)(env('POLYGON_API_TIMEOUT', 30));
        $this->maxRetries = (int)(env('POLYGON_API_RETRIES', 3));
        $this->logChannel = 'ingest';
    }

    /**
     * Fetch aggregated OHLCV data for a ticker between two dates.
     *
     * @param  string  $symbol      The ticker symbol (case-sensitive)
     * @param  int     $multiplier  The aggregation multiplier (usually 1)
     * @param  string  $timespan    The unit of time (e.g. 'day')
     * @param  string  $from        ISO date string (YYYY-MM-DD)
     * @param  string  $to          ISO date string (YYYY-MM-DD)
     * @param  array   $extraParams Optional extra query params
     * @return array   The list of returned bars (may be empty)
     */
    public function fetchAggregates(string $symbol, int $multiplier, string $timespan, string $from, string $to, array $extraParams = []): array
    {
        $endpoint = "/v2/aggs/ticker/{$symbol}/range/{$multiplier}/{$timespan}/{$from}/{$to}";
        $params = array_merge(['apiKey' => $this->apiKey, 'limit' => 50000], $extraParams);
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        $attempt = 0;
        $wait = 1;
        $log = Log::channel($this->logChannel);
        $startedAt = now()->toDateTimeString();

        $log->info("🧭 [{$startedAt}] FetchAggregates called", [
            'symbol' => $symbol,
            'endpoint' => $endpoint,
            'from' => $from,
            'to' => $to,
            'url' => $url,
        ]);

        while (true) {
            $attempt++;
            $log->debug("📡 Attempt {$attempt} to fetch {$symbol} (wait={$wait}s)");

            try {
                $resp = Http::timeout($this->timeout)->get($url);
                $status = $resp->status();
                $body = $resp->body();

                if ($resp->successful()) {
                    $json = $resp->json();

                    if (!is_array($json)) {
                        $log->error("❌ Invalid JSON response for {$symbol}", [
                            'status' => $status,
                            'body_snippet' => substr($body, 0, 300),
                        ]);
                        return [];
                    }

                    $count = $json['resultsCount'] ?? count($json['results'] ?? []);
                    $log->info("✅ Polygon response for {$symbol}: status={$status}, count={$count}", [
                        'queryCount' => $json['queryCount'] ?? null,
                        'statusField' => $json['status'] ?? null,
                    ]);

                    if ($count === 0) {
                        $log->warning("⚠️ No results returned for {$symbol}", [
                            'queryCount' => $json['queryCount'] ?? null,
                            'resultsCount' => $json['resultsCount'] ?? null,
                            'statusField' => $json['status'] ?? null,
                        ]);
                    }

                    return $json['results'] ?? [];
                }

                // Rate-limiting and error handling
                if ($status === 429) {
                    $retryAfter = $resp->header('Retry-After') ?? $wait;
                    $log->warning("⏳ 429 Too Many Requests for {$symbol}, retrying in {$retryAfter}s (attempt {$attempt})");
                    sleep((int)$retryAfter);
                } elseif ($resp->serverError()) {
                    $log->warning("⚠️ Server error for {$symbol}: status={$status}");
                    if ($attempt >= $this->maxRetries) {
                        $log->error("❌ Aborting {$symbol} after {$attempt} server errors");
                        break;
                    }
                    sleep($wait);
                    $wait *= 2;
                } else {
                    $log->warning("⚠️ Client error for {$symbol}: status={$status}", [
                        'body_snippet' => substr($body, 0, 300),
                    ]);
                    return [];
                }
            } catch (Throwable $e) {
                $log->error("💥 HTTP exception on attempt {$attempt} for {$symbol}: {$e->getMessage()}");
                if ($attempt >= $this->maxRetries) {
                    $log->error("🚫 Giving up on {$symbol} after {$attempt} attempts");
                    break;
                }
                sleep($wait);
                $wait *= 2;
            }

            if ($attempt >= ($this->maxRetries + 3)) {
                $log->error("🛑 Max retry limit reached for {$symbol}");
                break;
            }
        }

        $log->warning("🚫 Returning empty result for {$symbol} after {$attempt} attempts");
        return [];
    }

    /**
     * Upsert Polygon bar data into ticker_price_histories.
     *
     * v2.8.0 — Hardened Sanitization + Explicit Ticker
     * -----------------------------------------------
     * Adds an optional `$ticker` argument for explicit ticker string insertion.
     * Prevents SQLSTATE[HY000]: Field 'ticker' doesn't have a default value.
     *
     * @param  int         $tickerId
     * @param  string      $symbol
     * @param  string      $resolution
     * @param  array       $bars
     * @param  string|null $ticker   Optional plain-text ticker (fallback to $symbol)
     * @return int
     */
    public function upsertBars(int $tickerId, string $symbol, string $resolution, array $bars, ?string $ticker = null): int
    {
        $logger = Log::channel($this->logChannel);

        if (empty($bars)) {
            $logger->warning("⚠️ No bars provided to upsert for {$symbol}");
            return 0;
        }

        $now = Carbon::now()->toDateTimeString();
        $rows = [];
        $mappedCount = 0;

        foreach ($bars as $b) {
            try {
                if (empty($b['t'])) {
                    $logger->debug("⏳ Skipping bar with missing timestamp for {$symbol}");
                    continue;
                }

                // 🧩 SANITIZATION: Hardened numeric checks
                $fields = ['o', 'h', 'l', 'c', 'vw'];
                $valid = true;
                foreach ($fields as $field) {
                    if (!array_key_exists($field, $b)) {
                        continue;
                    }

                    $val = (float)$b[$field];
                    if (!is_finite($val) || $val <= 0 || $val > 10_000_000 || $val < 0.0001) {
                        $logger->warning("⚠️ Skipping abnormal {$field}={$b[$field]} for {$symbol} at {$b['t']}");
                        $valid = false;
                        break;
                    }
                }

                if (!$valid) {
                    continue;
                }

                // Convert timestamp
                $ts = Carbon::createFromTimestampMsUTC((int)$b['t'])->format('Y-m-d H:i:s');

                $rows[] = [
                    'ticker_id'   => $tickerId,
                    'ticker'      => $ticker ?? $symbol, // ✅ Ensures DB field always populated
                    'resolution'  => $resolution,
                    't'           => $ts,
                    'year'        => (int)substr($ts, 0, 4),
                    'o'           => $b['o'] ?? null,
                    'h'           => $b['h'] ?? null,
                    'l'           => $b['l'] ?? null,
                    'c'           => $b['c'] ?? null,
                    'v'           => isset($b['v']) ? (int)$b['v'] : null,
                    'vw'          => $b['vw'] ?? null,
                    'raw'         => json_encode($b),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];

                $mappedCount++;
            } catch (Throwable $e) {
                $logger->warning("⚠️ Skipped malformed bar for {$symbol}: {$e->getMessage()}");
            }
        }

        if ($mappedCount === 0) {
            $logger->warning("⚠️ All bars failed to map for {$symbol}");
            return 0;
        }

        try {
            $before = DB::table('ticker_price_histories')->where('ticker_id', $tickerId)->count();

            DB::table('ticker_price_histories')->upsert(
                $rows,
                ['ticker_id', 'resolution', 't'],
                ['o', 'h', 'l', 'c', 'v', 'vw', 'raw', 'updated_at']
            );

            $after = DB::table('ticker_price_histories')->where('ticker_id', $tickerId)->count();
            $inserted = max($after - $before, 0);

            $logger->info("💾 Upserted {$mappedCount} mapped bars for {$symbol} (net change: +{$inserted})");
            return $mappedCount;
        } catch (Throwable $e) {
            $logger->error("❌ Upsert failed for {$symbol}: {$e->getMessage()}");
            return 0;
        }
    }
}
