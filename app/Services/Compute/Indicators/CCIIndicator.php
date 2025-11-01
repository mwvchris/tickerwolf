<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * CCIIndicator
 *
 * Computes the Commodity Channel Index — a measure of how far the typical price
 * has diverged from its statistical mean.
 *
 * Math summary:
 *   TP = (High + Low + Close) / 3
 *   CCI = (TP - SMA(TP, N)) / (0.015 * MeanDeviation(TP, N))
 *
 * Characteristics:
 * - Values above +100 suggest overbought; below -100 suggest oversold.
 * - More responsive than RSI to short-term price volatility.
 *
 * Params:
 *   - period (int): lookback period. Default = 20.
 */
class CCIIndicator extends BaseIndicator
{
    public string $name = 'cci';
    public string $displayName = 'Commodity Channel Index';
    public bool $multiSeries = false;

    public array $defaults = [
        'period' => 20,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $period = (int)$opts['period'];

        // Compute Typical Price (TP)
        $tp = array_map(fn($b) => ($b['h'] + $b['l'] + $b['c']) / 3, $bars);
        $rows = [];

        // For each bar after enough history, compute CCI
        for ($i = $period - 1; $i < count($tp); $i++) {
            $slice = array_slice($tp, $i - $period + 1, $period);
            $sma = array_sum($slice) / $period;

            // Mean deviation of typical price
            $meanDev = array_sum(array_map(fn($v) => abs($v - $sma), $slice)) / $period;
            $cci = $meanDev != 0 ? ($tp[$i] - $sma) / (0.015 * $meanDev) : 0;

            $rows[] = [
                't' => $bars[$i]['t'],
                'indicator' => "cci_{$period}",
                'value' => round($cci, 6),
                'meta' => null,
            ];
        }

        return $rows;
    }
}