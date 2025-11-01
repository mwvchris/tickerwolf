<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * OBVIndicator
 *
 * Computes On-Balance Volume (OBV) — a cumulative volume-based momentum indicator.
 *
 * Math summary:
 *   OBV(t) = OBV(t-1) + Volume(t)   if Close(t) > Close(t-1)
 *             OBV(t-1) - Volume(t)   if Close(t) < Close(t-1)
 *             OBV(t-1)               if Close(t) = Close(t-1)
 *
 * Characteristics:
 * - Measures buying/selling pressure as cumulative volume flow.
 * - Confirms price trends or warns of potential reversals.
 * - Often used alongside moving averages or divergences.
 */
class OBVIndicator extends BaseIndicator
{
    public string $name = 'obv';
    public string $displayName = 'On-Balance Volume';
    public bool $multiSeries = false;

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $rows = [];

        $obv = 0.0;
        $prevClose = null;

        // Loop through each bar and accumulate OBV
        foreach ($bars as $i => $b) {
            if ($i === 0) {
                $prevClose = $b['c'];
                continue;
            }

            if ($b['c'] > $prevClose) {
                $obv += $b['v'];
            } elseif ($b['c'] < $prevClose) {
                $obv -= $b['v'];
            }

            $rows[] = [
                't' => $b['t'],
                'indicator' => 'obv',
                'value' => (float)$obv,
                'meta' => null,
            ];

            $prevClose = $b['c'];
        }

        return $rows;
    }
}