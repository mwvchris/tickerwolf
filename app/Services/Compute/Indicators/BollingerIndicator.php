<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * BollingerIndicator
 *
 * Computes Bollinger Bands around a simple moving average.
 *
 * Math summary:
 *   Mid  = SMA(C, period)
 *   Std  = rolling standard deviation(C, period)
 *   Upper = Mid + k * Std
 *   Lower = Mid - k * Std
 *
 * Characteristics:
 * - Dynamic volatility envelope.
 * - k = 2 (2σ) captures ~95% of price action statistically.
 * - Squeezes = low volatility, Breakouts = high volatility.
 */
class BollingerIndicator extends BaseIndicator
{
    public string $name = 'bb';
    public string $displayName = 'Bollinger Bands';
    public bool $multiSeries = true;

    public array $defaults = [
        'period' => 20,
        'stdevs' => 2.0,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $p = (int)$opts['period'];
        $k = (float)$opts['stdevs'];

        $closes = array_column($bars, 'c');
        $sma  = $this->rollingMean($closes, $p);
        $std  = $this->rollingStd($closes, $p);

        $outRows = [];
        foreach ($closes as $i => $_) {
            if ($sma[$i] === null || $std[$i] === null) continue;
            $mid = $sma[$i];
            $upper = $mid + $k * $std[$i];
            $lower = $mid - $k * $std[$i];
            $meta = json_encode([
                'mid'   => $mid,
                'upper' => $upper,
                'lower' => $lower,
                'stdev' => $std[$i],
            ]);
            $outRows[] = [
                't' => $bars[$i]['t'],
                'indicator' => "bb_{$p}_{$k}",
                'value' => (float)$mid,
                'meta' => $meta,
            ];
        }
        return $outRows;
    }
}