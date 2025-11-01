<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * VWAPIndicator
 *
 * Computes the Volume Weighted Average Price (VWAP).
 *
 * For daily resolution:
 *   - If Polygon provides 'vw', we use it directly.
 *   - Otherwise, we approximate:
 *       VWAP_t = Σ(P_typical_i * V_i) / Σ(V_i)
 *       where P_typical = (H + L + C) / 3
 *
 * Characteristics:
 * - Represents the average price weighted by volume.
 * - Often used as intraday fair value benchmark.
 */
class VWAPIndicator extends BaseIndicator
{
    public string $name = 'vwap';
    public string $displayName = 'Volume Weighted Average Price';
    public array $defaults = [];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $outRows = [];

        $cumPV = 0.0; // cumulative price*volume
        $cumV  = 0.0; // cumulative volume

        foreach ($bars as $i => $b) {
            $v  = (float)($b['v'] ?? 0);
            $vw = $b['vw'] ?? null;

            if ($vw !== null) {
                // Polygon’s daily VWAP field available
                $val = (float)$vw;
            } else {
                // Compute from running average of typical price * volume
                $tp = ((float)$b['h'] + (float)$b['l'] + (float)$b['c']) / 3.0;
                $cumPV += $tp * $v;
                $cumV  += $v;
                $val = $cumV > 0 ? $cumPV / $cumV : null;
            }

            if ($val !== null) {
                $outRows[] = [
                    't' => $b['t'],
                    'indicator' => 'vwap',
                    'value' => $val,
                    'meta' => null,
                ];
            }
        }

        return $outRows;
    }
}