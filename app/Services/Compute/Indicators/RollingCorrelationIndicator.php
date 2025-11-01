<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * RollingCorrelationIndicator
 *
 * Computes the rolling Pearson correlation coefficient between
 * two price series (e.g., the ticker vs a benchmark like SPY).
 *
 * Formula:
 *   Corr_t = Cov(X_t, Y_t) / (σ_X * σ_Y)
 *
 * Characteristics:
 * - Measures co-movement between two return streams.
 * - Range: [-1, +1], where 1 = perfect positive correlation.
 * - Useful for pair-trading and sector alignment studies.
 *
 * Params:
 * - window: int   → Rolling period (e.g., 20)
 * - benchmark: array<float> → Optional benchmark close series (must align in length)
 */
class RollingCorrelationIndicator extends BaseIndicator
{
    public string $name = 'rolling_corr';
    public string $displayName = 'Rolling Correlation Coefficient';
    public bool $multiSeries = false;

    public array $defaults = [
        'window' => 20,
        'benchmark' => [],
    ];

    /**
     * Compute rolling correlation between the ticker’s closes and a benchmark.
     */
    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);

        $window = (int)$opts['window'];
        $benchmark = $opts['benchmark'] ?? [];

        $closes = array_column($bars, 'c');

        // If no benchmark provided, correlation cannot be computed.
        if (empty($benchmark) || count($benchmark) < count($closes)) {
            return [];
        }

        // Compute daily returns
        $retStock = $this->returns($closes);
        $retBench = $this->returns($benchmark);

        // Align lengths
        $minCount = min(count($retStock), count($retBench));
        $retStock = array_slice($retStock, -$minCount);
        $retBench = array_slice($retBench, -$minCount);
        $bars = array_slice($bars, -$minCount);

        // Rolling correlation
        $corr = $this->rollingCorrelation($retStock, $retBench, $window);

        // Output rows
        $rows = [];
        foreach ($corr as $i => $val) {
            if ($val === null) continue;
            $rows[] = [
                't' => $bars[$i]['t'],
                'indicator' => 'rolling_corr_' . $window,
                'value' => (float)$val,
                'meta' => null,
            ];
        }

        return $rows;
    }
}