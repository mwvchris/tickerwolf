<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * BetaIndicator
 *
 * Computes the Beta coefficient of a stock relative to a benchmark index.
 *
 * Math summary:
 *   Beta = Covariance(stock_returns, benchmark_returns) / Variance(benchmark_returns)
 *
 * Characteristics:
 * - Measures sensitivity of a stock’s returns relative to market movement.
 * - Beta > 1: more volatile than market; < 1: less volatile.
 *
 * Params:
 *   - benchmark (array): benchmark series (e.g., S&P500 closes)
 *   - period (int): lookback window (default: 60)
 */
class BetaIndicator extends BaseIndicator
{
    public string $name = 'beta';
    public string $displayName = 'Beta Coefficient';
    public bool $multiSeries = false;

    public array $defaults = [
        'period' => 60,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $period = (int)$opts['period'];

        if (empty($params['beta']['benchmark'] ?? null)) {
            // No benchmark data → skip computation
            return [];
        }

        $benchmark = $params['beta']['benchmark'];
        $closes = array_column($bars, 'c');
        $rows = [];

        // Compute daily returns
        $retStock = $this->returns($closes);
        $retBench = $this->returns($benchmark);

        for ($i = $period; $i < count($retStock); $i++) {
            $stockSlice = array_slice($retStock, $i - $period, $period);
            $benchSlice = array_slice($retBench, $i - $period, $period);

            $cov = $this->covariance($stockSlice, $benchSlice);
            $var = $this->variance($benchSlice);
            $beta = $var != 0 ? $cov / $var : null;

            if ($beta !== null) {
                $rows[] = [
                    't' => $bars[$i]['t'],
                    'indicator' => "beta_{$period}",
                    'value' => round($beta, 6),
                    'meta' => null,
                ];
            }
        }

        return $rows;
    }
}