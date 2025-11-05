<?php

namespace App\Services\Validation\Validators;

use App\Services\Validation\Probes\PolygonProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ============================================================================
 *  PolygonDataValidator
 *  (v3.1.0 — Hybrid Range Verification + Configurable Quick Window)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Enhances live verification logic to support both "quick" (short look-back)
 *   and "full-range" Polygon API queries, selectable per call. Used by
 *   TickersIntegrityScanCommand v3.4+ for hybrid scanning performance.
 *
 * 🆕 v3.1.0 Enhancements:
 * ----------------------------------------------------------------------------
 *   • Adds `$fullRange` flag to verifyTickerUpstream($symbol, $fullRange = false)
 *   • Quick mode uses polygon.quick_verify_days_back (default 60)
 *   • Full range mode uses config date window (2020-01-01 → today)
 *   • Logs both window type and date boundaries for clarity
 *   • Retains retries, exponential back-off, and stub-detection logic
 *   • Produces uniform response structure (`resultsCount` + `count`)
 * ============================================================================
 */
class PolygonDataValidator
{
    public function __construct(protected PolygonProbe $probe) {}

    /**
     * ✅ Local DB validation
     */
    public function validateTicker(int $tickerId, string $symbol): array
    {
        $result = [
            'issues'      => [],
            'root_causes' => [],
            'upstream'    => null,
            'valid'       => true,
        ];

        $count = DB::table('ticker_price_histories')
            ->where('ticker_id', $tickerId)
            ->count();

        if ($count === 0) {
            $result['issues'][] = 'missing_price_data';
            $result['valid']    = false;

            $probeResult = $this->probe->checkTickerData($symbol);
            $result['upstream'] = $probeResult;

            if ($probeResult['found'] === false) {
                $result['root_causes']['missing_price_data'] = 'upstream_empty_response';
            } elseif ($probeResult['status'] !== 200) {
                $result['root_causes']['missing_price_data'] = 'upstream_error';
            } else {
                $result['root_causes']['missing_price_data'] = 'local_ingestion_failure';
            }
        } elseif ($count < 5) {
            $result['issues'][] = 'insufficient_price_data';
            $result['valid']    = false;
        }

        return $result;
    }

    /**
     * 🔍 Live Polygon.io verification (hybrid: quick or full range)
     *
     * @param string $symbol     The ticker symbol
     * @param bool   $fullRange  If true → use full historical window (slow)
     *                           If false → use quick look-back window (fast)
     */
    public function verifyTickerUpstream(string $symbol, bool $fullRange = false): array
    {
        $apiKey   = config('services.polygon.key');
        $attempts = (int) config('polygon.verify_stub_retry_attempts', 3);
        $delays   = (array) config('polygon.verify_stub_delay_schedule', [1, 3, 6]);

        /*
        |--------------------------------------------------------------------------
        | Determine time window (quick vs full)
        |--------------------------------------------------------------------------
        */
        if ($fullRange) {
            $from = config('polygon.price_history_min_date', '2020-01-01');
            $to   = config('polygon.price_history_max_date', Carbon::now()->toDateString());
            $rangeType = 'full';
        } else {
            $daysBack = (int) config('polygon.quick_verify_days_back', 60);
            $from     = Carbon::now()->subDays($daysBack)->toDateString();
            $to       = Carbon::now()->toDateString();
            $rangeType = "quick_{$daysBack}d";
        }

        $multiplier = config('polygon.default_multiplier', 1);
        $timespan   = config('polygon.default_timespan', 'day');
        $url        = "https://api.polygon.io/v2/aggs/ticker/{$symbol}/range/{$multiplier}/{$timespan}/{$from}/{$to}";

        /*
        |--------------------------------------------------------------------------
        | Retry logic (stub detection, back-off, and full JSON normalization)
        |--------------------------------------------------------------------------
        */
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $t0 = microtime(true);

            try {
                $resp = Http::timeout(config('polygon.request_timeout', 15))
                    ->withHeaders(['Accept' => 'application/json'])
                    ->get($url, [
                        'adjusted' => 'true',
                        'sort'     => 'asc',
                        'limit'    => 50000,
                        'apiKey'   => $apiKey,
                    ]);

                $elapsed = round((microtime(true) - $t0) * 1000, 2);
                $status  = $resp->status();
                $body    = $resp->body();
                $bodyLen = strlen($body);

                $json = $resp->json();
                if (!is_array($json)) {
                    $json = json_decode($body, true);
                }

                if (!is_array($json)) {
                    Log::channel('ingest')->error('❌ Invalid JSON from Polygon', [
                        'symbol' => $symbol,
                        'status' => $status,
                        'range'  => $rangeType,
                        'body_snippet' => substr($body, 0, 400),
                    ]);
                    return $this->emptyResponse($symbol, $status, 'invalid_json', $rangeType);
                }

                $polygonStatus = $json['status'] ?? 'UNKNOWN';
                $hasResults    = isset($json['results']) && is_array($json['results']);
                $count         = $json['resultsCount']
                    ?? $json['count']
                    ?? ($hasResults ? count($json['results']) : 0);
                $found = $count > 0;

                $isStub = (
                    $polygonStatus === 'DELAYED' &&
                    !$hasResults &&
                    $count === 0 &&
                    $bodyLen < 200
                );

                Log::channel('ingest')->info('📡 Polygon verifyTickerUpstream', [
                    'symbol'         => $symbol,
                    'attempt'        => $attempt,
                    'status'         => $status,
                    'polygon_status' => $polygonStatus,
                    'resultsCount'   => $count,
                    'found'          => $found,
                    'hasResults'     => $hasResults,
                    'isStub'         => $isStub,
                    'body_bytes'     => $bodyLen,
                    'latency_ms'     => $elapsed,
                    'from'           => $from,
                    'to'             => $to,
                    'range_type'     => $rangeType,
                ]);

                if ($isStub && $attempt < $attempts) {
                    $wait = $delays[$attempt - 1] ?? 2;
                    Log::channel('ingest')->warning("⏳ Polygon DELAYED stub detected — retrying in {$wait}s", [
                        'symbol'  => $symbol,
                        'attempt' => $attempt,
                        'range'   => $rangeType,
                    ]);
                    sleep($wait);
                    continue;
                }

                return [
                    'symbol'         => $symbol,
                    'status'         => $status,
                    'polygon_status' => $polygonStatus,
                    'found'          => $found,
                    'resultsCount'   => $count,
                    'count'          => $count,
                    'message'        => $found ? 'ok' : 'empty',
                    'range_type'     => $rangeType,
                    'checked_at'     => now()->toIso8601String(),
                ];

            } catch (\Throwable $e) {
                Log::channel('ingest')->error('❌ verifyTickerUpstream exception', [
                    'symbol'  => $symbol,
                    'attempt' => $attempt,
                    'range'   => $rangeType,
                    'error'   => $e->getMessage(),
                ]);

                if ($attempt < $attempts) {
                    $wait = $delays[$attempt - 1] ?? 2;
                    sleep($wait);
                    continue;
                }

                return $this->emptyResponse($symbol, 0, $e->getMessage(), $rangeType);
            }
        }

        return $this->emptyResponse($symbol, 0, 'max_attempts_exceeded', $rangeType);
    }

    /**
     * 🧩 Empty / fallback response factory
     */
    private function emptyResponse(string $symbol, int $status, string $message, string $rangeType = 'unknown'): array
    {
        return [
            'symbol'       => $symbol,
            'status'       => $status,
            'found'        => false,
            'resultsCount' => 0,
            'count'        => 0,
            'message'      => $message,
            'range_type'   => $rangeType,
            'checked_at'   => now()->toIso8601String(),
        ];
    }
}