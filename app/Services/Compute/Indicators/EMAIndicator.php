<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * EMAIndicator
 *
 * Computes exponential moving averages (EMA) on closing prices.
 *
 * Math summary:
 *   EMA_t = (C_t - EMA_{t-1}) * k + EMA_{t-1}, where k = 2 / (n + 1)
 *
 * Characteristics:
 * - Reacts faster than SMA by emphasizing recent prices.
 * - Common windows: 12, 26, 50, 200.
 * - Core component for MACD and trend-following systems.
 */
class EMAIndicator extends BaseIndicator
{
    public string $name = 'ema';
    public string $displayName = 'Exponential Moving Average';
    public array $defaults = [
        'windows' => [12, 26, 50, 200],
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $windows = $opts['windows'];

        $closes = array_column($bars, 'c');
        $outRows = [];

        foreach ($windows as $w) {
            $series = $this->ema($closes, (int)$w);
            foreach ($series as $i => $val) {
                if ($val === null) continue;
                $outRows[] = [
                    't' => $bars[$i]['t'],
                    'indicator' => "ema_{$w}",
                    'value' => (float)$val,
                    'meta' => null,
                ];
            }
        }

        return $outRows;
    }
}