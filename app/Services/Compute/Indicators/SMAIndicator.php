<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * SMAIndicator
 *
 * Computes simple moving averages (SMA) over closing prices.
 *
 * Math summary:
 *   SMA_t = (C_t + C_{t-1} + ... + C_{t-n+1}) / n
 *
 * Characteristics:
 * - Non-exponential, equal-weighted moving average.
 * - Smoother than raw price, but slower to react.
 * - Often used for support/resistance and trend confirmation.
 *
 * Extendability:
 * - Supports multiple windows (20, 50, 200 by default).
 * - Each window generates its own indicator series: "sma_20", "sma_50", etc.
 */
class SMAIndicator extends BaseIndicator
{
    public string $name = 'sma';
    public string $displayName = 'Simple Moving Average';
    public array $defaults = [
        'windows' => [20, 50, 200],
    ];

    public function compute(array $bars, array $params = []): array
    {
        // Normalize bar order to ascending timestamps
        $bars = $this->normalizeBars($bars);

        // Merge runtime params (from CLI/queue) with module defaults
        $opts = $this->opts($params);
        $windows = $opts['windows'];

        // Extract closing prices from bar data
        $closes = array_column($bars, 'c');
        $outRows = [];

        // For each requested window, compute SMA and produce one record per valid bar
        foreach ($windows as $w) {
            $series = $this->rollingMean($closes, (int)$w);
            foreach ($series as $i => $val) {
                if ($val === null) continue; // skip until enough bars exist
                $outRows[] = [
                    't' => $bars[$i]['t'],
                    'indicator' => "sma_{$w}",
                    'value' => (float)$val,
                    'meta' => null,
                ];
            }
        }

        return $outRows;
    }
}