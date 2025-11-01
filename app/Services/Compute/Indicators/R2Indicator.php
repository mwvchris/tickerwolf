<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * R2Indicator
 *
 * Computes the rolling coefficient of determination (R²) between
 * a ticker’s returns and a benchmark’s returns.
 *
 * Formula:
 *   R²_t = Corr(X_t, Y_t)^2
 *
 * Interpretation:
 * - Measures how much of a stock’s variance is explained by the benchmark.
 * - R² near 1 means highly benchmark-driven; near 0 means idiosyncratic.
 *
 * Params:
 * - window: int   → Rolling period (e.g., 60 days)
 * - benchmark: array<float> → Benchmark close series
 */
class R2Indicator extends BaseIndicator
{
    public string $name = 'r2';
    public string $displayName = 'Rolling R² (Coefficient of Determination)';
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

        // Compute returns and align
        $retStock = $this->returns($closes);
        $retBench = $this->returns($benchmark);
        $minCount = min(count($retStock), count($retBench));
        $retStock = array_slice($retStock, -$minCount);
        $retBench = array_slice($retBench, -$minCount);
        $bars = array_slice($bars, -$minCount);

        // Rolling correlation → R² = Corr²
        $corr = $this->rollingCorrelation($retStock, $retBench, $window);

        $rows = [];
        foreach ($corr as $i => $val) {
            if ($val === null) continue;
            $rows[] = [
                't' => $bars[$i]['t'],
                'indicator' => 'r2_' . $window,
                'value' => pow((float)$val, 2),
                'meta' => null,
            ];
        }

        return $rows;
    }
}