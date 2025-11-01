<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * ADXIndicator
 *
 * Computes the Average Directional Index — a trend strength indicator
 * derived from directional movement (+DI, -DI).
 *
 * Math summary:
 *   +DM = High_t - High_(t-1) if greater than (Low_(t-1) - Low_t)
 *   -DM = Low_(t-1) - Low_t   if greater than (High_t - High_(t-1))
 *   TR  = max(High - Low, abs(High - Close_(t-1)), abs(Low - Close_(t-1)))
 *
 *   +DI = 100 * (Smoothed +DM / Smoothed TR)
 *   -DI = 100 * (Smoothed -DM / Smoothed TR)
 *   DX  = 100 * |(+DI - -DI)| / (+DI + -DI)
 *   ADX = SMA(DX, N)
 *
 * Characteristics:
 * - Quantifies trend strength (0–100), independent of direction.
 * - ADX < 20 → weak trend; > 40 → strong trend.
 *
 * Params:
 *   - period (int): smoothing period. Default = 14.
 */
class ADXIndicator extends BaseIndicator
{
    public string $name = 'adx';
    public string $displayName = 'Average Directional Index';
    public bool $multiSeries = true;

    public array $defaults = [
        'period' => 14,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $period = (int)$opts['period'];

        $n = count($bars);
        $tr = $plusDM = $minusDM = [];

        // Step 1: Compute TR, +DM, and -DM
        for ($i = 1; $i < $n; $i++) {
            $upMove = $bars[$i]['h'] - $bars[$i - 1]['h'];
            $downMove = $bars[$i - 1]['l'] - $bars[$i]['l'];
            $plusDM[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0;
            $minusDM[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0;
            $tr[] = max(
                $bars[$i]['h'] - $bars[$i]['l'],
                abs($bars[$i]['h'] - $bars[$i - 1]['c']),
                abs($bars[$i]['l'] - $bars[$i - 1]['c'])
            );
        }

        // Step 2: Compute smoothed DI and DX
        $rows = [];
        for ($i = $period - 1; $i < count($tr); $i++) {
            $trN = array_sum(array_slice($tr, $i - $period + 1, $period));
            $plusDIN = 100 * (array_sum(array_slice($plusDM, $i - $period + 1, $period)) / $trN);
            $minusDIN = 100 * (array_sum(array_slice($minusDM, $i - $period + 1, $period)) / $trN);
            $dx = ($plusDIN + $minusDIN) > 0 ? (abs($plusDIN - $minusDIN) / ($plusDIN + $minusDIN)) * 100 : 0;

            $rows[] = [
                't' => $bars[$i + 1]['t'],
                'indicator' => "adx_{$period}",
                'value' => round($dx, 6),
                'meta' => json_encode([
                    '+DI' => round($plusDIN, 6),
                    '-DI' => round($minusDIN, 6),
                ]),
            ];
        }

        return $rows;
    }
}