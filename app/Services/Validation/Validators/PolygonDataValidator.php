<?php

namespace App\Services\Validation\Validators;

use App\Services\Validation\Probes\PolygonProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 *  PolygonDataValidator (v2.2 — adds live verifyTickerUpstream)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Bridges local Polygon.io–sourced data validation with live upstream
 *   verification. Performs both local completeness checks and on-the-fly
 *   Polygon API probes.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • validateTicker() → existing local+upstream completeness logic.
 *   • verifyTickerUpstream() → NEW: direct live API check for recent bars.
 * ============================================================================
 *   This validator acts as the “bridge” between **local data health** and
 *   **Polygon upstream truth**. It performs a two-phase analysis:
 *
 *   1️⃣ **Local Completeness Check**
 *       - Confirms the ticker has sufficient rows in `ticker_price_histories`
 *         for the expected date range (e.g. several hundred trading days).
 *       - Flags any tickers with *zero* records or extremely small counts
 *         (`< 5` bars total) as “missing_price_data”.
 *       - Optionally could later expand to detect *partial ranges* (e.g.,
 *         a symbol with data only from 2024 but missing 2023).
 *
 *   2️⃣ **Upstream Root-Cause Probe**
 *       - When local data is missing or empty, the validator calls
 *         `PolygonProbe::checkTickerData()` to determine whether Polygon.io
 *         also reports no data.
 *       - This distinguishes:
 *           🟢 Upstream Empty (Polygon has no data) → “upstream_empty_response”
 *           🟠 Local Failure  (Polygon has data, but we failed to ingest it)
 *           🔴 Upstream Error (Polygon API returned 4xx/5xx or rate-limit)
 *
 *   3️⃣ **Gap and Coverage Analysis**
 *       - The validator can be extended to check for time gaps between
 *         local bars (e.g. ≥5 consecutive missing weekdays) to identify
 *         *partial data availability* cases.
 *       - These “coverage anomalies” will be surfaced as
 *         `'partial_price_history' => ['missing_days' => N]` in results.
 *
 * 🧮 Decision Logic:
 * ----------------------------------------------------------------------------
 *   • If 0 bars locally → mark `missing_price_data` and call PolygonProbe.
 *   • If 1–4 bars → mark as “insufficient sample”.
 *   • If Polygon returns HTTP 200 but empty → `upstream_empty_response`.
 *   • If Polygon returns non-200 → `upstream_error`.
 *   • If Polygon returns valid bars but none locally → `local_ingestion_failure`.
 *
 * 📦 Example Result:
 * ----------------------------------------------------------------------------
 *   [
 *     'issues'       => ['missing_price_data'],
 *     'root_causes'  => ['missing_price_data' => 'upstream_empty_response'],
 *     'upstream'     => ['status' => 200, 'found' => false],
 *     'valid'        => false,
 *   ]
 *
 * ============================================================================
 */
class PolygonDataValidator
{
    public function __construct(protected PolygonProbe $probe) {}

    /**
     * Validate Polygon-sourced data for one ticker (local + probe).
     */
    public function validateTicker(int $tickerId, string $symbol): array
    {
        $result = [
            'issues'      => [],
            'root_causes' => [],
            'upstream'    => null,
            'valid'       => true,
        ];

        // 1️⃣ Local completeness check
        $count = DB::table('ticker_price_histories')
            ->where('ticker_id', $tickerId)
            ->count();

        if ($count === 0) {
            $result['issues'][] = 'missing_price_data';
            $result['valid']    = false;

            // 2️⃣ Probe Polygon upstream (cached probe class)
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
     * 🔍 Live Polygon.io verification for a ticker (read-only).
     *
     * @param  string  $symbol
     * @param  int     $daysBack  Defaults to 30
     * @return array<string,mixed>
     */
    public function verifyTickerUpstream(string $symbol, int $daysBack = 30): array
    {
        $apiKey = config('services.polygon.key');
        $now  = now()->format('Y-m-d');
        $past = now()->subDays($daysBack)->format('Y-m-d');
        $url  = "https://api.polygon.io/v2/aggs/ticker/{$symbol}/range/1/day/{$past}/{$now}";

        try {
            $resp = Http::timeout(8)->get($url, ['apiKey' => $apiKey, 'limit' => 5]);
            $status = $resp->status();

            if ($status === 429) {
                Log::warning("⚠️ Polygon rate-limit hit during verifyTickerUpstream", ['symbol' => $symbol]);
                return [
                    'symbol'     => $symbol,
                    'status'     => 429,
                    'found'      => false,
                    'count'      => 0,
                    'message'    => 'rate_limited',
                    'checked_at' => now()->toIso8601String(),
                ];
            }

            if (!$resp->ok()) {
                return [
                    'symbol'     => $symbol,
                    'status'     => $status,
                    'found'      => false,
                    'count'      => 0,
                    'message'    => "HTTP {$status}",
                    'checked_at' => now()->toIso8601String(),
                ];
            }

            $json  = $resp->json();
            $count = $json['resultsCount'] ?? 0;
            $found = $count > 0;

            return [
                'symbol'     => $symbol,
                'status'     => $status,
                'found'      => $found,
                'count'      => $count,
                'message'    => $found ? 'ok' : 'empty',
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::error("❌ verifyTickerUpstream failed", [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
            return [
                'symbol'     => $symbol,
                'status'     => 0,
                'found'      => false,
                'count'      => 0,
                'message'    => $e->getMessage(),
                'checked_at' => now()->toIso8601String(),
            ];
        }
    }
}