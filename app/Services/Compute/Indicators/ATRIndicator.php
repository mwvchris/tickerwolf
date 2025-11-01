<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * ATRIndicator
 *
 * Computes the Average True Range — a measure of volatility.
 *
 * Math summary:
 *   TR_t = max(High_t - Low_t, |High_t - Close_{t-1}|, |Low_t - Close_{t-1}|)
 *   ATR_t = (ATR_{t-1} * (n - 1) + TR_t) / n  (Wilder’s smoothing)
 *
 * Characteristics:
 * - Captures both intraday and gap volatility.
 * - Unbounded — higher values = higher volatility.
 * - Common period: 14.
 */
class ATRIndicator extends BaseIndicator
{
    public string $name = 'atr';
    public string $displayName = 'Average True Range';
    public array $defaults = [
        'period' => 14,
        'wilder' => true,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $p = (int)$opts['period'];

        $highs  = array_column($bars, 'h');
        $lows   = array_column($bars, 'l');
        $closes = array_column($bars, 'c');

        $tr = $this->trueRangeSeries($highs, $lows, $closes);
        $n  = count($tr);
        $atr = array_fill(0, $n, null);

        if ($n === 0 || $p <= 0) return [];

        // Wilder’s ATR smoothing: recursive moving average
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            if ($i < $p) {
                $sum += (float)$tr[$i];
                if ($i === $p - 1) $atr[$i] = $sum / $p;
            } else {
                $atr[$i] = (($atr[$i - 1] * ($p - 1)) + (float)$tr[$i]) / $p;
            }
        }

        // Build output rows
        $outRows = [];
        foreach ($atr as $i => $val) {
            if ($val === null) continue;
            $outRows[] = [
                't' => $bars[$i]['t'],
                'indicator' => "atr_{$p}",
                'value' => (float)$val,
                'meta' => null,
            ];
        }

        return $outRows;
    }
}