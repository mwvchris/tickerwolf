<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * RSIIndicator
 *
 * Computes the RSI (Relative Strength Index), a momentum oscillator.
 *
 * Math summary:
 *   RSI = 100 - [100 / (1 + RS)], where RS = avgGain / avgLoss
 *   Uses Wilder’s smoothing for avgGain/avgLoss.
 *
 * Characteristics:
 * - Bounded [0,100].
 * - Commonly interpreted as:
 *   - Overbought: RSI > 70
 *   - Oversold: RSI < 30
 * - Period 14 is the classical default.
 */
class RSIIndicator extends BaseIndicator
{
    public string $name = 'rsi';
    public string $displayName = 'Relative Strength Index';
    public array $defaults = [
        'period' => 14,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $p = (int)$opts['period'];

        $closes = array_column($bars, 'c');
        $series = $this->rsi($closes, $p);

        $outRows = [];
        foreach ($series as $i => $val) {
            if ($val === null) continue;
            $outRows[] = [
                't' => $bars[$i]['t'],
                'indicator' => "rsi_{$p}",
                'value' => (float)$val,
                'meta' => null,
            ];
        }
        return $outRows;
    }
}