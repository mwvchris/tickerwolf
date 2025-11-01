<?php

namespace App\Services\Compute;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Class BaseIndicator
 *
 * The abstract base for all indicator modules.
 *
 * Responsibilities:
 * - Provide a stable interface for computing indicators from OHLCV bars.
 * - Offer common math/statistical utilities (SMA, EMA, RSI pieces, rolling stdev, True Range, etc.).
 * - Normalize/prepare bar inputs and merge module defaults with runtime params.
 * - Bar normalization (ensures consistent structure for OHLCV data)
 * - Provide shared math and statistical utilities.
 * - Offer rolling-window tools for advanced financial analytics.
 * - Parameter merging (`opts()`)
 *
 * Contract:
 * - Child classes must implement compute(array $bars, array $params = []): array
 * - compute() returns an array of "rows" (ready to upsert), each row:
 *   [
 *     't'         => 'YYYY-MM-DD HH:MM:SS',
 *     'indicator' => 'sma_20' (or similar),
 *     'value'     => float|null, // the main series value for that indicator at time t
 *     'meta'      => ?string,    // optional JSON string for auxiliary series (e.g., MACD signal/histogram)
 *   ]
 *
 * Design goals:
 * - Keep computation modules clean and math-focused.
 * - Indicators remain math-only and stateless.
 * - No DB or API calls at this layer.
 * - Consistent time alignment and normalized bar inputs.
 * - Provide reusable helpers for time-series operations.
 * - All derived classes must define:
 *      public string $name;
 *      public string $displayName;
 *      public bool $multiSeries;
 *      public array  $defaults;
 * 
 * Notes:
 * - Keep heavy math stateless and pure; avoid DB access here.
 * - Logging is minimal at this layer; orchestration logs live in pipeline/job/command.
 */

abstract class BaseIndicator
{
    /** @var string Short name of indicator (e.g., 'sma', 'rsi', 'macd'). */
    public string $name;

    /** @var string Human-readable display name. */
    public string $displayName = '';

    /** @var array Default parameters for this indicator. */
    public array $defaults = [];

    /** @var bool Whether this indicator outputs multiple sub-series. */
    public bool $multiSeries = false;

    /* ======================================================================
     * ABSTRACT CONTRACT
     * ====================================================================== */

    /**
     * Compute normalized indicator rows for upsert.
     *
     * @param array<int, array{t:string,o:?float,h:?float,l:?float,c:?float,v:?float,vw:?float}> $bars
     * @param array $params
     * @return array<int, array{t:string,indicator:string,value:?float,meta:?string}>
     */
    abstract public function compute(array $bars, array $params = []): array;

    /* ======================================================================
     * CORE NORMALIZATION UTILITIES
     * ====================================================================== */

    protected function opts(array $params): array
    {
        return array_replace_recursive($this->defaults, $params[$this->name] ?? []);
    }

    protected function normalizeBars(array $bars): array
    {
        usort($bars, fn($a, $b) => strcmp($a['t'], $b['t']));
        return $bars;
    }

    /* ======================================================================
     * COMMON MATH / STAT HELPERS
     * ====================================================================== */

    /** Rolling simple moving average (SMA). */
    protected function rollingMean(array $values, int $window): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($window <= 0 || $window > $n) return $out;

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += (float)($values[$i] ?? 0.0);
            if ($i >= $window) $sum -= (float)($values[$i - $window] ?? 0.0);
            if ($i >= $window - 1) $out[$i] = $sum / $window;
        }
        return $out;
    }

    /** Exponential Moving Average (EMA). */
    protected function ema(array $values, int $window): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($window <= 0 || $window > $n) return $out;

        $sum = array_sum(array_slice($values, 0, $window));
        $emaPrev = $sum / $window;
        $out[$window - 1] = $emaPrev;

        $k = 2 / ($window + 1);
        for ($i = $window; $i < $n; $i++) {
            $price = (float)($values[$i] ?? 0.0);
            $emaPrev = ($price - $emaPrev) * $k + $emaPrev;
            $out[$i] = $emaPrev;
        }
        return $out;
    }

    /** RSI (Relative Strength Index) using Wilder’s method. */
    protected function rsi(array $closes, int $period): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($period <= 0 || $period >= $n) return $out;

        $gains = [];
        $losses = [];
        for ($i = 1; $i < $n; $i++) {
            $delta = (float)($closes[$i] ?? 0.0) - (float)($closes[$i - 1] ?? 0.0);
            $gains[$i] = $delta > 0 ? $delta : 0.0;
            $losses[$i] = $delta < 0 ? -$delta : 0.0;
        }

        $avgGain = array_sum(array_slice($gains, 1, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 1, $period)) / $period;

        $out[$period] = $avgLoss == 0.0 ? 100.0 : 100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss)));

        for ($i = $period + 1; $i < $n; $i++) {
            $avgGain = (($avgGain * ($period - 1)) + ($gains[$i] ?? 0.0)) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + ($losses[$i] ?? 0.0)) / $period;
            $out[$i] = $avgLoss == 0.0 ? 100.0 : 100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss)));
        }
        return $out;
    }

    /** Rolling population standard deviation. */
    protected function rollingStd(array $values, int $window): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($window <= 1 || $window > $n) return $out;

        $sum = 0.0;
        $sumSq = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float)($values[$i] ?? 0.0);
            $sum += $x;
            $sumSq += $x * $x;

            if ($i >= $window) {
                $xrm = (float)($values[$i - $window] ?? 0.0);
                $sum -= $xrm;
                $sumSq -= $xrm * $xrm;
            }

            if ($i >= $window - 1) {
                $mean = $sum / $window;
                $var = max(0.0, ($sumSq / $window) - ($mean * $mean));
                $out[$i] = sqrt($var);
            }
        }
        return $out;
    }

    /** True Range (for ATR). */
    protected function trueRangeSeries(array $highs, array $lows, array $closes): array
    {
        $n = count($highs);
        $tr = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            $h = (float)($highs[$i] ?? 0.0);
            $l = (float)($lows[$i] ?? 0.0);
            $cPrev = $i > 0 ? (float)($closes[$i - 1] ?? 0.0) : null;
            $tr[$i] = max($h - $l, $cPrev !== null ? abs($h - $cPrev) : 0.0, $cPrev !== null ? abs($l - $cPrev) : 0.0);
        }
        return $tr;
    }

    /* ======================================================================
     * EXTENDED STATISTICS
     * ====================================================================== */

    protected function returns(array $closes): array
    {
        $returns = [];
        for ($i = 1; $i < count($closes); $i++) {
            $prev = (float)($closes[$i - 1] ?? 0.0);
            $curr = (float)($closes[$i] ?? 0.0);
            $returns[] = ($prev == 0.0) ? 0.0 : ($curr - $prev) / $prev;
        }
        return $returns;
    }

    protected function variance(array $values): float
    {
        $values = array_filter($values, fn($v) => is_numeric($v));
        $n = count($values);
        if ($n === 0) return 0.0;
        $mean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) $sumSq += ($v - $mean) ** 2;
        return $sumSq / $n;
    }

    protected function stddev(array $values): float
    {
        return sqrt($this->variance($values));
    }

    protected function covariance(array $x, array $y): float
    {
        $n = min(count($x), count($y));
        if ($n === 0) return 0.0;

        $x = array_slice($x, 0, $n);
        $y = array_slice($y, 0, $n);

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) $sum += ($x[$i] - $meanX) * ($y[$i] - $meanY);
        return $sum / $n;
    }

    /* ======================================================================
     * GENERIC ROLLING UTILITIES
     * ====================================================================== */

    protected function rollingApply(array $values, int $window, callable $callback): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($window <= 0 || $window > $n) return $out;

        for ($i = $window - 1; $i < $n; $i++) {
            $slice = array_slice($values, $i - $window + 1, $window);
            $slice = array_filter($slice, fn($v) => is_numeric($v));
            $out[$i] = !empty($slice) ? $callback($slice) : null;
        }

        return $out;
    }

    protected function rollingMeanStd(array $values, int $window): array
    {
        $n = count($values);
        $mean = array_fill(0, $n, null);
        $std = array_fill(0, $n, null);
        if ($window <= 1 || $window > $n) return compact('mean', 'std');

        $sum = 0.0;
        $sumSq = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $x = (float)($values[$i] ?? 0.0);
            $sum += $x;
            $sumSq += $x * $x;

            if ($i >= $window) {
                $xrm = (float)($values[$i - $window] ?? 0.0);
                $sum -= $xrm;
                $sumSq -= $xrm * $xrm;
            }

            if ($i >= $window - 1) {
                $mean[$i] = $sum / $window;
                $var = max(0.0, ($sumSq / $window) - ($mean[$i] * $mean[$i]));
                $std[$i] = sqrt($var);
            }
        }

        return compact('mean', 'std');
    }

    /* ======================================================================
     * ADVANCED ROLLING METRICS
     * ====================================================================== */

    /**
     * Rolling correlation between two time series.
     * 
     * Corr_t = Cov(X_t, Y_t) / (σ_X * σ_Y)
     */
    protected function rollingCorrelation(array $x, array $y, int $window): array
    {
        $n = min(count($x), count($y));
        $out = array_fill(0, $n, null);
        if ($window <= 1 || $window > $n) return $out;

        for ($i = $window - 1; $i < $n; $i++) {
            $xSlice = array_slice($x, $i - $window + 1, $window);
            $ySlice = array_slice($y, $i - $window + 1, $window);

            $cov = $this->covariance($xSlice, $ySlice);
            $stdX = $this->stddev($xSlice);
            $stdY = $this->stddev($ySlice);

            $out[$i] = ($stdX > 0 && $stdY > 0) ? $cov / ($stdX * $stdY) : null;
        }

        return $out;
    }

    /**
     * Rolling beta: slope of regression of X (stock) vs Y (benchmark).
     * 
     * Beta_t = Cov(X_t, Y_t) / Var(Y_t)
     */
    protected function rollingBeta(array $x, array $y, int $window): array
    {
        $n = min(count($x), count($y));
        $out = array_fill(0, $n, null);
        if ($window <= 1 || $window > $n) return $out;

        for ($i = $window - 1; $i < $n; $i++) {
            $xSlice = array_slice($x, $i - $window + 1, $window);
            $ySlice = array_slice($y, $i - $window + 1, $window);

            $varY = $this->variance($ySlice);
            $covXY = $this->covariance($xSlice, $ySlice);

            $out[$i] = ($varY > 0) ? $covXY / $varY : null;
        }

        return $out;
    }

    /* ======================================================================
     * TIMESTAMP NORMALIZATION
     * ====================================================================== */

    protected function toTimestampString($t): string
    {
        if ($t instanceof \DateTimeInterface) return $t->format('Y-m-d H:i:s');
        if (is_numeric($t)) return Carbon::createFromTimestamp((int)$t)->toDateTimeString();
        return (string)$t;
    }
}