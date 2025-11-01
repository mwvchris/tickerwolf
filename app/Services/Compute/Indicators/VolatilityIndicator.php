<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * VolatilityIndicator
 *
 * Computes rolling price volatility using standard deviation of returns.
 *
 * Math summary:
 *   Volatility = StdDev(returns over N) * sqrt(252)
 *
 * Characteristics:
 * - Represents annualized historical volatility (percentage).
 * - Core metric for portfolio risk and derivative pricing.
 *
 * Params:
 *   - period (int): rolling window (default: 20)
 */
class VolatilityIndicator extends BaseIndicator
{
    public string $name = 'volatility';
    public string $displayName = 'Historical Volatility';
    public bool $multiSeries = false;

    public array $defaults = [
        'period' => 20,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $period = (int)$opts['period'];

        $closes = array_column($bars, 'c');
        $returns = $this->returns($closes);
        $rows = [];

        // Rolling volatility (annualized)
        for ($i = $period; $i < count($returns); $i++) {
            $slice = array_slice($returns, $i - $period, $period);
            $std = $this->stddev($slice);
            $vol = $std * sqrt(252); // annualize
            $rows[] = [
                't' => $bars[$i]['t'],
                'indicator' => "volatility_{$period}",
                'value' => round($vol * 100, 6), // convert to %
                'meta' => json_encode(['std' => $std]),
            ];
        }

        return $rows;
    }
}