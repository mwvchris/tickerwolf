<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * SharpeRatioIndicator
 *
 * Computes the Sharpe Ratio — a measure of risk-adjusted return.
 *
 * Math summary:
 *   Sharpe = (Mean(Returns) - RiskFreeRate) / StdDev(Returns)
 *
 * Characteristics:
 * - Evaluates reward per unit of volatility.
 * - Higher values indicate better risk-adjusted performance.
 *
 * Params:
 *   - period (int): rolling window (default: 60)
 *   - risk_free (float): annualized risk-free rate (default: 0.02)
 */
class SharpeRatioIndicator extends BaseIndicator
{
    public string $name = 'sharpe_ratio';
    public string $displayName = 'Sharpe Ratio';
    public bool $multiSeries = false;

    public array $defaults = [
        'period' => 60,
        'risk_free' => 0.02,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $period = (int)$opts['period'];
        $rf = (float)$opts['risk_free'];

        $closes = array_column($bars, 'c');
        $returns = $this->returns($closes);
        $rows = [];

        for ($i = $period; $i < count($returns); $i++) {
            $slice = array_slice($returns, $i - $period, $period);
            $mean = array_sum($slice) / count($slice);
            $std = $this->stddev($slice);
            $sharpe = $std != 0 ? ($mean - ($rf / 252)) / $std : null; // 252 trading days/year

            if ($sharpe !== null) {
                $rows[] = [
                    't' => $bars[$i]['t'],
                    'indicator' => "sharpe_{$period}",
                    'value' => round($sharpe, 6),
                    'meta' => json_encode(['mean_return' => $mean, 'std' => $std]),
                ];
            }
        }

        return $rows;
    }
}