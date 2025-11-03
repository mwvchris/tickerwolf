<?php

namespace App\Services\Validation\Probes;

use App\Services\PolygonApiClient;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 *  PolygonProbe
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Lightweight utility that checks whether Polygon.io
 *   currently has any valid upstream data for a given ticker.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Calls PolygonApiClient (aggregate or reference endpoint)
 *   • Distinguishes "no data" vs "upstream error"
 *   • Returns structured probe results used by validators
 *
 * 📦 Typical Response:
 *   [
 *     'status'   => 200,
 *     'latency'  => 83,
 *     'found'    => true,
 *     'message'  => 'OK',
 *     'raw_size' => 4201
 *   ]
 * ============================================================================
 */
class PolygonProbe
{
    public function __construct(protected PolygonApiClient $client) {}

    /**
     * Test whether Polygon.io has recent data for a ticker.
     *
     * @param  string  $symbol
     * @return array<string,mixed>
     */
    public function checkTickerData(string $symbol): array
    {
        $start = microtime(true);

        try {
            // Query the most recent daily aggregate (lightweight)
            $response = $this->client->get("/v2/aggs/ticker/{$symbol}/prev");
            $elapsed  = round((microtime(true) - $start) * 1000, 2);

            if (empty($response) || !isset($response['results']) || count($response['results']) === 0) {
                return [
                    'status'  => $response['status'] ?? 200,
                    'latency' => $elapsed,
                    'found'   => false,
                    'message' => 'Upstream empty response',
                    'raw_size'=> 0,
                ];
            }

            return [
                'status'   => 200,
                'latency'  => $elapsed,
                'found'    => true,
                'message'  => 'OK',
                'raw_size' => strlen(json_encode($response)),
            ];
        } catch (\Throwable $e) {
            $elapsed = round((microtime(true) - $start) * 1000, 2);
            Log::channel('ingest')->warning('⚠️ Polygon probe failed', [
                'symbol'  => $symbol,
                'message' => $e->getMessage(),
            ]);

            return [
                'status'  => 500,
                'latency' => $elapsed,
                'found'   => false,
                'message' => $e->getMessage(),
                'raw_size'=> 0,
            ];
        }
    }
}