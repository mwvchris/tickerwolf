<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Ticker;
use Throwable;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class PolygonIndicatorsService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $maxRetries;
    protected string $logChannel;
    protected int $pageLimit;
    protected int $yearChunkSize; // years per request window

    public function __construct()
    {
        $this->baseUrl       = rtrim(config('services.polygon.base') ?? env('POLYGON_API_BASE', 'https://api.polygon.io'), '/');
        $this->apiKey        = (string) (config('services.polygon.key') ?? env('POLYGON_API_KEY'));
        $this->timeout       = (int) (env('POLYGON_API_TIMEOUT', 30));
        $this->maxRetries    = (int) (env('POLYGON_API_RETRIES', 3));
        $this->logChannel    = 'ingest';
        $this->pageLimit     = 5000;
        $this->yearChunkSize = .5; // request per .5-year slice
    }

    /**
     * Ingest multiple indicators for a single ticker across a date range.
     */
    public function fetchIndicators(string $ticker, array $indicators, array $range = []): void
    {
        $ticker = strtoupper(trim($ticker));
        $tickerModel = Ticker::where('ticker', $ticker)->first();

        if (! $tickerModel) {
            Log::channel($this->logChannel)->warning("⚠️ Indicators: unknown ticker, skipping", [
                'ticker' => $ticker,
            ]);
            return;
        }

        foreach ($indicators as $ind) {
            try {
                [$type, $period] = $this->parseIndicator($ind);
                Log::channel($this->logChannel)->info("▶️ Fetching indicator", [
                    'ticker' => $ticker,
                    'indicator' => $ind,
                    'type' => $type,
                    'period' => $period,
                    'range' => $range,
                ]);

                $values = $this->fetchIndicatorDataChunked($ticker, $type, $period, $range);

                if (! empty($values)) {
                    $count = $this->storeIndicators($tickerModel->id, '1d', $ind, $values, $type);
                    Log::channel($this->logChannel)->info("✅ Stored indicator data", [
                        'ticker' => $ticker,
                        'indicator' => $ind,
                        'records_inserted_or_updated' => $count,
                    ]);
                } else {
                    Log::channel($this->logChannel)->warning("⚠️ No indicator data returned", [
                        'ticker' => $ticker,
                        'indicator' => $ind,
                    ]);
                }
            } catch (Throwable $e) {
                Log::channel($this->logChannel)->error("❌ Indicator ingestion failed", [
                    'ticker' => $ticker,
                    'indicator' => $ind,
                    'message' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 1000),
                ]);
            }

            usleep(150_000); // pacing between API calls
        }
    }

    /** Parse "sma_20" → ['sma', 20]; "macd" → ['macd', null] */
    protected function parseIndicator(string $name): array
    {
        if (preg_match('/^(sma|ema|rsi)_(\d+)$/i', $name, $m)) {
            return [strtolower($m[1]), (int) $m[2]];
        }
        return [strtolower($name), null]; // e.g. macd
    }

    /**
     * Fetch an indicator from Polygon with date chunking (e.g., 1-year slices).
     */
    protected function fetchIndicatorDataChunked(string $ticker, string $type, ?int $window, array $range = []): array
    {
        $from = ! empty($range['from']) ? Carbon::parse($range['from']) : now()->subYears(10);
        $to   = ! empty($range['to'])   ? Carbon::parse($range['to'])   : now();
        $allValues = [];

        // build year-based chunks
        $periods = CarbonPeriod::create($from, "{$this->yearChunkSize} year", $to);
        $chunks = [];

        foreach ($periods as $p) {
            $start = $p->copy();
            $end   = $p->copy()->addYears($this->yearChunkSize)->subDay();
            if ($end->gt($to)) $end = $to;
            $chunks[] = ['from' => $start->toDateString(), 'to' => $end->toDateString()];
        }

        foreach ($chunks as $i => $chunk) {
            $values = $this->fetchIndicatorData($ticker, $type, $window, $chunk);

            Log::channel($this->logChannel)->info("📆 Chunk fetched", [
                'ticker' => $ticker,
                'indicator_type' => $type,
                'chunk_index' => $i + 1,
                'from' => $chunk['from'],
                'to' => $chunk['to'],
                'records' => count($values),
            ]);

            if (! empty($values)) {
                $allValues = array_merge($allValues, $values);
            }

            usleep(250_000); // small pause between chunks
        }

        return $allValues;
    }

    /**
     * Fetch indicator data for a single chunk.
     */
    protected function fetchIndicatorData(string $ticker, string $type, ?int $window, array $range = []): array
    {
        $endpoint = "/v1/indicators/{$type}/{$ticker}";
        $url = $this->baseUrl . $endpoint;

        $params = [
            'apiKey'      => $this->apiKey,
            'timespan'    => 'day',
            'series_type' => 'close',
            'limit'       => $this->pageLimit,
        ];

        if ($window) $params['window'] = $window;
        if (! empty($range['from'])) $params['from'] = $range['from'];
        if (! empty($range['to']))   $params['to']   = $range['to'];

        $attempt = 0;
        $wait = 2;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            try {
                $resp = Http::timeout($this->timeout)->get($url, $params);

                if ($resp->successful()) {
                    $json = $resp->json();
                    return $json['results']['values'] ?? [];
                }

                if ($resp->status() === 429) {
                    $retryAfter = (int) ($resp->header('Retry-After') ?? $wait);
                    Log::channel($this->logChannel)->warning("⚠️ 429 rate limited", [
                        'ticker' => $ticker,
                        'indicator_type' => $type,
                        'retry_after' => $retryAfter,
                    ]);
                    sleep($retryAfter);
                    continue;
                }

                Log::channel($this->logChannel)->error("❌ HTTP failure", [
                    'ticker' => $ticker,
                    'indicator_type' => $type,
                    'status' => $resp->status(),
                    'body' => substr($resp->body(), 0, 300),
                ]);
                break;
            } catch (Throwable $e) {
                Log::channel($this->logChannel)->error("❌ Request exception", [
                    'ticker' => $ticker,
                    'indicator_type' => $type,
                    'message' => $e->getMessage(),
                ]);
                sleep($wait);
                $wait = min($wait * 2, 30);
            }
        }

        return [];
    }

    /**
     * Store indicator rows into ticker_indicators.
     */
    protected function storeIndicators(int $tickerId, string $resolution, string $indicator, array $values, string $type): int
    {
        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($values as $v) {
            $ts = isset($v['timestamp'])
                ? now()->createFromTimestampMs((int) $v['timestamp'])->toDateTimeString()
                : null;

            if (! $ts) continue;

            $value = $v['value'] ?? null;
            $meta  = null;

            if ($type === 'macd') {
                $macd      = $v['macd']      ?? null;
                $signal    = $v['signal']    ?? null;
                $histogram = $v['histogram'] ?? null;

                $value = $macd;
                $meta  = json_encode(array_filter([
                    'signal' => $signal,
                    'histogram' => $histogram,
                ], fn($x) => $x !== null));
            }

            $rows[] = [
                'ticker_id'  => $tickerId,
                'resolution' => $resolution,
                't'          => $ts,
                'indicator'  => $indicator,
                'value'      => $value,
                'meta'       => $meta,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) return 0;

        DB::table('ticker_indicators')->upsert(
            $rows,
            ['ticker_id', 'resolution', 't', 'indicator'],
            ['value', 'meta', 'updated_at']
        );

        return count($rows);
    }

    public function ingestMany(array $tickers, array $indicators, array $range = []): void
    {
        foreach ($tickers as $ticker) {
            $this->fetchIndicators($ticker, $indicators, $range);
        }
    }
}