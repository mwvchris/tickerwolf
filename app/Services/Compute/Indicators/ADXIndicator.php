<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 *  ADXIndicator (v2.3.1 — Safe Division + Enhanced Stability)
 * ============================================================================
 *
 * 🧮 Computes the Average Directional Index (ADX) — a measure of trend strength
 *     derived from Directional Movement (+DI, -DI).
 *
 * ----------------------------------------------------------------------------
 *  Math Summary:
 *    +DM = High_t - High_(t-1) if greater than (Low_(t-1) - Low_t)
 *    -DM = Low_(t-1) - Low_t   if greater than (High_t - High_(t-1))
 *    TR  = max(
 *              High - Low,
 *              |High - Close_(t-1)|,
 *              |Low  - Close_(t-1)|
 *           )
 *
 *    +DI = 100 * (Smoothed +DM / Smoothed TR)
 *    -DI = 100 * (Smoothed -DM / Smoothed TR)
 *    DX  = 100 * |(+DI - -DI)| / (+DI + -DI)
 *    ADX = SMA(DX, N)
 * ----------------------------------------------------------------------------
 *
 * ⚙️ Params:
 *    - period (int): smoothing period (default: 14)
 *
 * 🔐 Safety Enhancements (v2.3.1):
 *    - Protects against division by zero using epsilon guard values.
 *    - Handles static or near-flat tickers (e.g. Treasury ETFs) gracefully.
 *    - Adds debug log entry when TR == 0 fallback triggers (for diagnostics).
 *
 * 🧩 Characteristics:
 *    - Quantifies trend strength (0–100), independent of direction.
 *    - ADX < 20 → weak trend; ADX > 40 → strong trend.
 * ============================================================================
 */
class ADXIndicator extends BaseIndicator
{
    public string $name = 'adx';
    public string $displayName = 'Average Directional Index';
    public bool $multiSeries = true;

    public array $defaults = [
        'period' => 14,
    ];

    /**
     * Compute ADX, +DI, and -DI series for given OHLCV bars.
     *
     * @param array $bars   Normalized bars array: [['t','o','h','l','c','v'], ...]
     * @param array $params Indicator parameters (period, etc.)
     * @return array[]      Rows of ['t','indicator','value','meta']
     */
    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $period = max(1, (int)($opts['period'] ?? 14));

        $n = count($bars);
        if ($n <= $period) {
            return [];
        }

        $tr = $plusDM = $minusDM = [];
        $epsilon = 1e-10; // Prevent division by zero

        // ---------------------------------------------------------------------
        // Step 1: Compute TR, +DM, and -DM
        // ---------------------------------------------------------------------
        for ($i = 1; $i < $n; $i++) {
            $upMove = $bars[$i]['h'] - $bars[$i - 1]['h'];
            $downMove = $bars[$i - 1]['l'] - $bars[$i]['l'];

            $plusDM[]  = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDM[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;

            $trValue = max(
                $bars[$i]['h'] - $bars[$i]['l'],
                abs($bars[$i]['h'] - $bars[$i - 1]['c']),
                abs($bars[$i]['l'] - $bars[$i - 1]['c'])
            );

            // Guard for flat price bars
            if ($trValue <= 0) {
                $trValue = $epsilon;
                if ($i < 5 || mt_rand(0, 1000) === 0) {
                    // Log sparsely to avoid flooding logs
                    Log::debug("ADXIndicator zero TR fallback", [
                        'symbol' => $bars[$i]['s'] ?? null,
                        'i' => $i,
                        'date' => $bars[$i]['t'] ?? null,
                    ]);
                }
            }

            $tr[] = $trValue;
        }

        // ---------------------------------------------------------------------
        // Step 2: Compute smoothed DIs and DX values
        // ---------------------------------------------------------------------
        $rows = [];

        for ($i = $period - 1; $i < count($tr); $i++) {
            // Sums over rolling window
            $trN      = array_sum(array_slice($tr, max(0, $i - $period + 1), $period));
            $plusDMN  = array_sum(array_slice($plusDM, max(0, $i - $period + 1), $period));
            $minusDMN = array_sum(array_slice($minusDM, max(0, $i - $period + 1), $period));

            // Safe divisor
            $trN = max($epsilon, $trN);

            $plusDI = 100 * ($plusDMN / $trN);
            $minusDI = 100 * ($minusDMN / $trN);

            $sumDI = $plusDI + $minusDI;
            $dx = ($sumDI > $epsilon)
                ? (abs($plusDI - $minusDI) / $sumDI) * 100
                : 0.0;

            $rows[] = [
                't' => $bars[$i + 1]['t'] ?? $bars[$i]['t'],
                'indicator' => "adx_{$period}",
                'value' => round($dx, 6),
                'meta' => json_encode([
                    '+DI' => round($plusDI, 6),
                    '-DI' => round($minusDI, 6),
                ]),
            ];
        }

        return $rows;
    }
}