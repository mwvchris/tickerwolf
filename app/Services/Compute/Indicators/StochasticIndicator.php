<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * StochasticIndicator
 *
 * Computes the Stochastic Oscillator — a momentum indicator comparing
 * the current close to a range of recent highs/lows.
 *
 * Math summary:
 *   %K = 100 * (Close - LowestLow(N)) / (HighestHigh(N) - LowestLow(N))
 *   %D = SMA(%K, 3)
 *
 * Characteristics:
 * - Measures overbought/oversold conditions (0–100 scale).
 * - %K is the fast line; %D (3-period SMA) is the signal line.
 * - Common parameters: period = 14.
 *
 * Params:
 *   - period (int): lookback for %K. Default = 14.
 */
class StochasticIndicator extends BaseIndicator
{
    public string $name = 'stochastic';
    public string $displayName = 'Stochastic Oscillator';
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
        $kArr = [];
        $rows = [];

        // Compute %K line
        for ($i = $period - 1; $i < $n; $i++) {
            $window = array_slice($bars, $i - $period + 1, $period);
            $highs = array_column($window, 'h');
            $lows  = array_column($window, 'l');
            $close = $bars[$i]['c'];

            $highN = max($highs);
            $lowN  = min($lows);
            $k = ($highN - $lowN) != 0 ? 100 * (($close - $lowN) / ($highN - $lowN)) : 0;
            $kArr[$i] = $k;

            $rows[] = [
                't' => $bars[$i]['t'],
                'indicator' => "stoch_k_{$period}",
                'value' => round($k, 6),
                'meta' => null,
            ];
        }

        // Compute %D (3-period SMA of %K)
        $dRows = [];
        $dPeriod = 3;
        for ($i = $period - 1 + $dPeriod - 1; $i < $n; $i++) {
            $slice = array_slice($kArr, $i - $dPeriod + 1, $dPeriod, true);
            $avg = array_sum($slice) / count($slice);
            $dRows[] = [
                't' => $bars[$i]['t'],
                'indicator' => "stoch_d_{$period}",
                'value' => round($avg, 6),
                'meta' => null,
            ];
        }

        return array_merge($rows, $dRows);
    }
}