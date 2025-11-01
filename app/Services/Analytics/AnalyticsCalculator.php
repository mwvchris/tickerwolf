<?php

namespace App\Services\Analytics;

use App\Models\Ticker;
use App\Models\TickerIndicator;
use App\Models\TickerPriceHistory;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ============================================================================
 *  AnalyticsCalculator
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Provides **derived analytics** for each ticker based on historical price data.
 *   These secondary metrics feed long-horizon feature snapshots and ML pipelines.
 *
 * 🧠 Core Responsibilities:
 * ----------------------------------------------------------------------------
 *   • Compute rolling Sharpe ratios
 *   • Compute annualized volatility
 *   • Compute drawdowns (relative to prior peaks)
 *   • Compute beta (vs. benchmark, e.g., SPY)
 *
 * 💡 Notes:
 * ----------------------------------------------------------------------------
 *   • All computations are performed on daily close prices (`resolution = 1d`)
 *   • Outputs are keyed by date for direct merging into feature snapshots
 *   • Returns are log-based (ln(Pt / Pt-1)) for stability with small values
 *   • Nulls are inserted for periods before the rolling window length
 *
 * ============================================================================
 */
class AnalyticsCalculator
{
    /**
     * Default benchmark symbol (used for beta computation).
     * You can override this in config/analytics.php or per-call if needed.
     */
    protected string $benchmarkTicker = 'SPY';

    /**
     * Compute derived analytics for a ticker across the specified range.
     *
     * @param  int         $tickerId   Target ticker ID
     * @param  string|null $from       Range start date (YYYY-MM-DD)
     * @param  string|null $to         Range end date (YYYY-MM-DD)
     * @return array<string,array<string,mixed>>  Keyed by date → metric → {value, meta}
     */
    public function computeDerivedAnalytics(int $tickerId, ?string $from, ?string $to): array
    {
        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Load ticker price history
        |--------------------------------------------------------------------------
        */
        $bars = TickerPriceHistory::query()
            ->where('ticker_id', $tickerId)
            ->where('resolution', '1d')
            ->when($from, fn($q) => $q->where('t', '>=', $from))
            ->when($to, fn($q) => $q->where('t', '<=', $to))
            ->orderBy('t', 'asc')
            ->get(['t', 'c'])
            ->map(fn($r) => ['t' => Carbon::parse($r->t)->toDateString(), 'c' => (float) $r->c])
            ->all();

        if (count($bars) < 2) {
            Log::channel('ingest')->warning('⚠️ Insufficient bars for derived analytics', [
                'ticker_id' => $tickerId,
                'bars'      => count($bars),
            ]);
            return [];
        }

        $closes  = array_column($bars, 'c');
        $dates   = array_column($bars, 't');
        $returns = $this->returns($closes);

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Compute primary derived metrics
        |--------------------------------------------------------------------------
        */
        $sharpe     = $this->rollingSharpe($returns, $dates, 60);
        $volatility = $this->rollingVolatility($returns, $dates, 30);
        $drawdown   = $this->rollingDrawdown($closes, $dates);

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Compute Beta (vs. benchmark)
        |--------------------------------------------------------------------------
        | Loads the benchmark (SPY) returns and aligns date ranges before computing
        | rolling 60-day beta as Cov(ticker, benchmark) / Var(benchmark).
        */
        $beta = $this->rollingBeta($returns, $dates, $this->benchmarkTicker, 60);

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Merge all metrics by date
        |--------------------------------------------------------------------------
        */
        $derived = [];
        foreach ($dates as $i => $date) {
            $derived[$date] = [
                'sharpe_60'     => ['value' => $sharpe[$i]     ?? null, 'meta' => null],
                'volatility_30' => ['value' => $volatility[$i] ?? null, 'meta' => null],
                'drawdown'      => ['value' => $drawdown[$i]   ?? null, 'meta' => null],
                'beta_60'       => ['value' => $beta[$i]       ?? null, 'meta' => ['benchmark' => $this->benchmarkTicker]],
            ];
        }

        return $derived;
    }

    // =========================================================================
    // 🧮 Helper Calculations
    // =========================================================================

    /**
     * Compute simple daily log returns.
     */
    protected function returns(array $closes): array
    {
        $r = [];
        for ($i = 1; $i < count($closes); $i++) {
            $prev = $closes[$i - 1];
            $curr = $closes[$i];
            $r[] = $prev > 0 ? log($curr / $prev) : 0;
        }
        return $r;
    }

    /**
     * Rolling Sharpe ratio (annualized).
     */
    protected function rollingSharpe(array $returns, array $dates, int $period): array
    {
        $out = [];
        for ($i = 0; $i < count($returns); $i++) {
            if ($i < $period) { $out[] = null; continue; }
            $slice = array_slice($returns, $i - $period, $period);
            $mean  = array_sum($slice) / $period;
            $std   = $this->stddev($slice);
            $out[] = $std > 0 ? ($mean / $std) * sqrt(252) : null;
        }
        array_unshift($out, null);
        return $out;
    }

    /**
     * Rolling volatility (annualized standard deviation).
     */
    protected function rollingVolatility(array $returns, array $dates, int $period): array
    {
        $out = [];
        for ($i = 0; $i < count($returns); $i++) {
            if ($i < $period) { $out[] = null; continue; }
            $slice = array_slice($returns, $i - $period, $period);
            $out[] = $this->stddev($slice) * sqrt(252);
        }
        array_unshift($out, null);
        return $out;
    }

    /**
     * Rolling drawdown percentage relative to prior peak.
     */
    protected function rollingDrawdown(array $closes, array $dates): array
    {
        $peak = -INF;
        $out = [];
        foreach ($closes as $c) {
            $peak = max($peak, $c);
            $out[] = $peak > 0 ? ($c - $peak) / $peak : 0;
        }
        return $out;
    }

    /**
     * Rolling Beta vs benchmark (default SPY).
     */
    protected function rollingBeta(array $returns, array $dates, string $benchmarkTicker, int $period): array
    {
        /*
        |--------------------------------------------------------------------------
        | 🔍 1️⃣ Resolve benchmark ticker_id
        |--------------------------------------------------------------------------
        */
        $benchmarkId = Ticker::where('ticker', $benchmarkTicker)->value('id');
        if (!$benchmarkId) {
            Log::channel('ingest')->warning("⚠️ Benchmark ticker not found for beta computation", [
                'benchmark' => $benchmarkTicker,
            ]);
            return array_fill(0, count($returns), null);
        }

        /*
        |--------------------------------------------------------------------------
        | 🔢 2️⃣ Load benchmark closes over same range
        |--------------------------------------------------------------------------
        */
        $benchmarkBars = TickerPriceHistory::query()
            ->where('ticker_id', $benchmarkId)
            ->where('resolution', '1d')
            ->whereBetween('t', [min($dates), max($dates)])
            ->orderBy('t', 'asc')
            ->get(['t', 'c'])
            ->mapWithKeys(fn($r) => [Carbon::parse($r->t)->toDateString() => (float) $r->c])
            ->all();

        if (empty($benchmarkBars)) {
            Log::channel('ingest')->warning("⚠️ No benchmark price data available for {$benchmarkTicker}", [
                'benchmark_id' => $benchmarkId,
            ]);
            return array_fill(0, count($returns), null);
        }

        /*
        |--------------------------------------------------------------------------
        | 🧭 3️⃣ Align benchmark closes to ticker dates (forward-fill missing)
        |--------------------------------------------------------------------------
        */
        $bmCloses = [];
        foreach ($dates as $d) {
            if (isset($benchmarkBars[$d])) {
                $bmCloses[] = $benchmarkBars[$d];
            } else {
                // forward-fill with last known value
                $bmCloses[] = end($bmCloses) ?: reset($benchmarkBars);
            }
        }

        $bmReturns = $this->returns($bmCloses);
        if (count($bmReturns) < 2) {
            Log::channel('ingest')->warning("⚠️ Insufficient benchmark returns for beta computation", [
                'benchmark' => $benchmarkTicker,
            ]);
            return array_fill(0, count($returns), null);
        }

        /*
        |--------------------------------------------------------------------------
        | 📊 4️⃣ Compute rolling beta
        |--------------------------------------------------------------------------
        */
        $out = [];
        for ($i = 0; $i < count($returns); $i++) {
            if ($i < $period || $i >= count($bmReturns)) {
                $out[] = null;
                continue;
            }
            $sliceA = array_slice($returns, $i - $period, $period);
            $sliceB = array_slice($bmReturns, $i - $period, $period);
            $cov = $this->covariance($sliceA, $sliceB);
            $var = $this->variance($sliceB);
            $out[] = ($var > 0) ? $cov / $var : null;
        }

        array_unshift($out, null);
        return $out;
    }

    // =========================================================================
    // 📊 Statistical Helpers
    // =========================================================================

    protected function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;
        $mean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) $sumSq += pow($v - $mean, 2);
        return sqrt($sumSq / ($n - 1));
    }

    protected function variance(array $values): float
    {
        $std = $this->stddev($values);
        return $std ** 2;
    }

    protected function covariance(array $x, array $y): float
    {
        $n = min(count($x), count($y));
        if ($n < 2) return 0.0;
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;
        $cov = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $cov += ($x[$i] - $meanX) * ($y[$i] - $meanY);
        }
        return $cov / ($n - 1);
    }
}