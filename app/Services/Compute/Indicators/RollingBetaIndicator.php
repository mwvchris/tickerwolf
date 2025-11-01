<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * RollingBetaIndicator
 *
 * Computes the rolling beta of a stock relative to a benchmark index.
 *
 * Formula:
 *   Beta_t = Cov(returns_stock_t, returns_bench_t) / Var(returns_bench_t)
 *
 * Characteristics:
 * - Captures systematic risk over a rolling window.
 * - Beta > 1 → stock is more volatile than benchmark.
 * - Beta < 1 → stock moves less than benchmark.
 *
 * Params:
 * - window: int   → Rolling period (default: 60 days)
 * - benchmark: array<float> → Benchmark close series (must align in length)
 */
class RollingBetaIndicator extends BaseIndicator
{
    public string $name = 'rolling_beta';
    public string $displayName = 'Rolling Beta';
    public bool $multiSeries = false;

    public array $defaults = [
        'window' => 60,
        'benchmark' => [],
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);

        $window = (int)$opts['window'];
        $benchmark = $opts['benchmark'] ?? [];

        $closes = array_column($bars, 'c');
        if (empty($benchmark) || count($benchmark) < count($closes)) {
            return [];
        }

        // Compute returns for both
        $retStock = $this->returns($closes);
        $retBench = $this->returns($benchmark);

        $minCount = min(count($retStock), count($retBench));
        $retStock = array_slice($retStock, -$minCount);
        $retBench = array_slice($retBench, -$minCount);
        $bars = array_slice($bars, -$minCount);

        // Rolling beta
        $beta = $this->rollingBeta($retStock, $retBench, $window);

        $rows = [];
        foreach ($beta as $i => $val) {
            if ($val === null) continue;
            $rows[] = [
                't' => $bars[$i]['t'],
                'indicator' => 'rolling_beta_' . $window,
                'value' => (float)$val,
                'meta' => null,
            ];
        }

        return $rows;
    }
}