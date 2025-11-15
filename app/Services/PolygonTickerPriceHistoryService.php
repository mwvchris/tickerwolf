<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ============================================================================
 *  PolygonTickerPriceHistoryService (v3.0.0)
 * ============================================================================
 *
 *  Ultra-fast, multi-resolution (1d + 1h) price history ingestion engine.
 *
 *  ✔ Shared fetch method for all resolutions
 *  ✔ Minimal logging (but rich error context)
 *  ✔ Bulk upsert of mapped bar rows
 *  ✔ Auto-from helpers for both 1d + 1h
 *  ✔ 1h retention purge (fast indexed delete)
 *  ✔ Safe numeric constraints on OHLCV fields
 * ============================================================================
 */
class PolygonTickerPriceHistoryService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $maxRetries;
    protected string $logChannel;

    public function __construct()
    {
        $this->baseUrl    = rtrim(config('services.polygon.base') ?? 'https://api.polygon.io', '/');
        $this->apiKey     = config('services.polygon.key');
        $this->timeout    = 20;
        $this->maxRetries = 3;
        $this->logChannel = 'ingest';
    }

    /**
     * =========================================================================
     *  PUBLIC API ENTRYPOINT
     * =========================================================================
     *
     * Fetch and store bars of any resolution ('1d', '1h') for a ticker.
     */
    public function fetchAndStoreBars(
        string $symbol,
        int $tickerId,
        string $resolution,
        Carbon $from,
        Carbon $to
    ): void {
        $logger = Log::channel($this->logChannel);

        try {
            $logger->info("📡 Fetching {$resolution} bars", [
                'symbol' => $symbol,
                'from'   => $from->toDateTimeString(),
                'to'     => $to->toDateTimeString(),
            ]);

            $multiplier = ($resolution === '1h') ? 1 : 1;
            $timespan   = ($resolution === '1h') ? 'hour' : 'day';

            $bars = $this->fetchAggregates(
                $symbol,
                $multiplier,
                $timespan,
                $from->toDateString(),
                $to->toDateString()
            );

            if (empty($bars)) {
                $logger->warning("⚠️ No {$resolution} bars returned", [
                    'symbol' => $symbol,
                    'resolution' => $resolution,
                ]);
                return;
            }

            $mapped = $this->mapBars($tickerId, $symbol, $resolution, $bars);

            if (!empty($mapped)) {
                $this->bulkUpsert($mapped);
                $logger->info("💾 Stored bars", [
                    'symbol'     => $symbol,
                    'resolution' => $resolution,
                    'count'      => count($mapped),
                ]);
            }
        } catch (Throwable $e) {
            $logger->error("❌ Exception during {$resolution} ingest", [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
                'trace'  => substr($e->getTraceAsString(), 0, 500),
            ]);
        }
    }

    /**
     * =========================================================================
     *  FETCH AGGREGATES
     * =========================================================================
     */
    public function fetchAggregates(
        string $symbol,
        int $multiplier,
        string $timespan,
        string $from,
        string $to
    ): array {
        $endpoint = "/v2/aggs/ticker/{$symbol}/range/{$multiplier}/{$timespan}/{$from}/{$to}";
        $url = $this->baseUrl . $endpoint . "?apiKey={$this->apiKey}&limit=50000";

        $logger = Log::channel($this->logChannel);
        $attempt = 0;
        $wait = 1;

        while ($attempt < $this->maxRetries) {
            $attempt++;

            try {
                $resp = Http::timeout($this->timeout)->get($url);

                if ($resp->successful()) {
                    $json = $resp->json();
                    return $json['results'] ?? [];
                }

                if ($resp->status() === 429) {
                    sleep($wait);
                    $wait *= 2;
                    continue;
                }

                // All other non-429 errors → return empty
                return [];
            } catch (Throwable $e) {
                if ($attempt >= $this->maxRetries) {
                    return [];
                }
                sleep($wait);
                $wait *= 2;
            }
        }

        return [];
    }

    /**
     * =========================================================================
     *  MAP BARS → TICKER_PRICE_HISTORIES ROWS
     * =========================================================================
     */
    protected function mapBars(
        int $tickerId,
        string $ticker,
        string $resolution,
        array $bars
    ): array {
        $mapped = [];
        $now = Carbon::now()->toDateTimeString();
        $logger = Log::channel($this->logChannel);

        foreach ($bars as $b) {
            try {
                if (empty($b['t'])) {
                    continue;
                }

                // Validate numeric fields
                foreach (['o', 'h', 'l', 'c', 'vw'] as $fld) {
                    if (isset($b[$fld])) {
                        $val = floatval($b[$fld]);
                        if (!is_finite($val) || $val <= 0 || $val > 10_000_000) {
                            continue 2; // Skip bar
                        }
                    }
                }

                $ts = Carbon::createFromTimestampMsUTC((int)$b['t'])
                    ->format('Y-m-d H:i:s');

                $mapped[] = [
                    'ticker_id'   => $tickerId,
                    'ticker'      => $ticker,
                    'resolution'  => $resolution,
                    't'           => $ts,
                    'year'        => intval(substr($ts, 0, 4)),
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

            } catch (Throwable $e) {
                $logger->warning("⚠️ Bar skip", [
                    'ticker'     => $ticker,
                    'resolution' => $resolution,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $mapped;
    }

    /**
     * =========================================================================
     *  BULK UPSERT
     * =========================================================================
     */
    protected function bulkUpsert(array $rows): void
    {
        DB::table('ticker_price_histories')->upsert(
            $rows,
            ['ticker_id', 'resolution', 't'],
            ['o','h','l','c','v','vw','raw','updated_at']
        );
    }

    /**
     * =========================================================================
     *  AUTO-FROM HELPERS
     * =========================================================================
     */
    public function getLatestTimestamp(int $tickerId, string $resolution): ?string
    {
        return DB::table('ticker_price_histories')
            ->where('ticker_id', $tickerId)
            ->where('resolution', $resolution)
            ->orderByDesc('t')
            ->value('t');
    }

    /**
     * =========================================================================
     *  1H RETENTION PURGE
     * =========================================================================
     */
    public function purgeOld1h(Carbon $cutoff): int
    {
        return DB::table('ticker_price_histories')
            ->where('resolution', '1h')
            ->where('t', '<', $cutoff)
            ->delete();
    }
}