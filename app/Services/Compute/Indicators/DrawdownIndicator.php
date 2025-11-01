<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * DrawdownIndicator
 *
 * Computes the maximum drawdown — the largest peak-to-trough decline over time.
 *
 * Math summary:
 *   Drawdown = (Peak - Current) / Peak
 *
 * Characteristics:
 * - Quantifies downside risk and volatility.
 * - Expressed as a fraction or percentage.
 *
 * Params:
 *   - none (computed cumulatively)
 */
class DrawdownIndicator extends BaseIndicator
{
    public string $name = 'drawdown';
    public string $displayName = 'Maximum Drawdown';
    public bool $multiSeries = false;

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $rows = [];

        $peak = -INF;

        foreach ($bars as $b) {
            $peak = max($peak, $b['c']);
            $dd = $peak > 0 ? (($peak - $b['c']) / $peak) * 100 : 0;

            $rows[] = [
                't' => $b['t'],
                'indicator' => 'drawdown',
                'value' => round($dd, 6),
                'meta' => json_encode(['peak' => $peak]),
            ];
        }

        return $rows;
    }
}