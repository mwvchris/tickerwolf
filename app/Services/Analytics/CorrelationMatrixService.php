<?php

namespace App\Services\Analytics;

use App\Models\Ticker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 *  CorrelationMatrixService  (v4.0 — Config-Aware, Chunked, Fully Documented)
 * =============================================================================
 *
 * PURPOSE
 * --------
 * This service computes **pairwise correlation**, **beta**, and **R²**
 * coefficients between all (or a subset of) active tickers based on their
 * daily price history, and persists the results into the `ticker_correlations`
 * table for analytical and visualization use.
 *
 * It is the analytical backbone for:
 *   • Heatmap correlation dashboards
 *   • Sector/industry co-movement analysis
 *   • Portfolio and LLM-driven narrative generation (“AAPL ↔ NVDA +0.92”)
 *
 * -----------------------------------------------------------------------------
 * DESIGN OVERVIEW
 * -----------------------------------------------------------------------------
 * 1️⃣  Data Source
 *     Uses `ticker_price_histories` as the canonical price source.
 *     Only daily candles (`resolution = '1d'`) are used for correlation.
 *
 * 2️⃣  Data Window
 *     Loads closing prices for a configurable lookback period
 *     (default: 120 days) and computes per-ticker **log returns**.
 *
 * 3️⃣  Pairwise Computation
 *     Computes correlations in **block-tiled** fashion (chunk×chunk)
 *     to prevent O(n²) memory blowups. Default chunk: 200 tickers.
 *
 * 4️⃣  Statistical Metrics
 *     • Correlation (Pearson)
 *     • Beta = cov(A,B) / var(B)
 *     • R²   = corr²
 *
 * 5️⃣  Data Integrity
 *     • Canonical ordering enforced: (a < b)
 *     • Requires minimum overlap of observations (default: 20)
 *     • Guards against NaN/INF/zero-variance
 *
 * 6️⃣  Persistence
 *     • Bulk upsert into `ticker_correlations` every N pairs (default: 5000)
 *     • Uses ON DUPLICATE KEY UPDATE semantics via Laravel’s upsert()
 *
 * 7️⃣  Configuration
 *     All tunables live in `/config/correlation.php`, under `defaults`.
 *     Command-line overrides (e.g. --window, --lookback) always take priority.
 *
 * -----------------------------------------------------------------------------
 * PERFORMANCE CHARACTERISTICS
 * -----------------------------------------------------------------------------
 * • Time complexity:  O(n²) pairs (chunked to bound memory)
 * • Space complexity: O(chunk²)
 * • Typical use: run nightly or periodically as a batch job.
 *
 * -----------------------------------------------------------------------------
 * EXAMPLES
 * -----------------------------------------------------------------------------
 * ```bash
 * php artisan compute:correlation-matrix
 * php artisan compute:correlation-matrix --window=60 --lookback=180
 * php artisan compute:correlation-matrix --limit=500 --chunk=100
 * ```
 *
 * -----------------------------------------------------------------------------
 * OUTPUT
 * -----------------------------------------------------------------------------
 * Each record in `ticker_correlations`:
 *   ticker_id_a | ticker_id_b | as_of_date | corr | beta | r2 | timestamps
 *
 * -----------------------------------------------------------------------------
 */
class CorrelationMatrixService extends BaseAnalytics
{
    /**
     * Primary entry point for correlation matrix computation.
     *
     * @param  int|null  $lookbackDays   Number of calendar days of price data to load
     * @param  int|null  $window         Rolling window size (number of returns)
     * @param  int|null  $chunkSize      Ticker block size for memory tiling
     * @param  int|null  $limit          Optional cap on ticker count
     * @param  int|null  $minOverlap     Minimum overlapping observations required
     * @return void
     */
    public function computeMatrix(
        ?int $lookbackDays = null,
        ?int $window = null,
        ?int $chunkSize = null,
        ?int $limit = null,
        ?int $minOverlap = null
    ): void {
        /*
        |--------------------------------------------------------------------------
        | 0️⃣ Load configuration defaults
        |--------------------------------------------------------------------------
        */
        $cfg = config('correlation.defaults');
        $lookbackDays = $lookbackDays ?? $cfg['lookback_days'];
        $window       = $window ?? $cfg['window'];
        $chunkSize    = $chunkSize ?? $cfg['chunk_size'];
        $minOverlap   = $minOverlap ?? $cfg['min_overlap'];
        $flushEvery   = $cfg['flush_every'];

        $asOf = now()->toDateString();

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Resolve active ticker universe
        |--------------------------------------------------------------------------
        | We restrict to `active = true` to avoid stale/delisted symbols.
        | Optionally, a `--limit` can cap the number of tickers for testing.
        */
        $tickerQuery = Ticker::query()
            ->where('active', true)
            ->orderBy('id')
            ->select(['id', 'ticker']);

        if ($limit && $limit > 0) {
            $tickerQuery->limit($limit);
        }

        $tickers = $tickerQuery->get();
        $ids     = $tickers->pluck('id')->all();
        $n       = count($ids);

        if ($n < 2) {
            Log::channel('ingest')->warning('⚠️ Not enough tickers for correlation computation', [
                'count' => $n,
            ]);
            return;
        }

        Log::channel('ingest')->info('▶️ Starting correlation matrix', [
            'tickers'      => $n,
            'lookbackDays' => $lookbackDays,
            'window'       => $window,
            'chunkSize'    => $chunkSize,
            'minOverlap'   => $minOverlap,
            'as_of'        => $asOf,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Load recent daily closes for all tickers in one bulk query
        |--------------------------------------------------------------------------
        | Pull only the required lookback window from `ticker_price_histories`.
        | The query benefits from existing indexes on (ticker_id, resolution, t).
        */
        $since = now()->subDays($lookbackDays)->toDateString();

        $rows = DB::table('ticker_price_histories')
            ->whereIn('ticker_id', $ids)
            ->where('resolution', '1d')
            ->whereDate('t', '>=', $since)
            ->orderBy('ticker_id')
            ->orderBy('t')
            ->get([
                'ticker_id',
                DB::raw('DATE(t) as d'),
                'c',
            ]);

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Transform raw rows into per-ticker closing-price maps
        |--------------------------------------------------------------------------
        | Resulting structure:
        |   $closesByTicker[123] = ['2025-01-01' => 123.45, '2025-01-02' => 124.10, ...]
        */
        $closesByTicker = [];
        foreach ($rows as $r) {
            if ($r->c !== null) {
                $closesByTicker[(int)$r->ticker_id][$r->d] = (float)$r->c;
            }
        }
        unset($rows); // free memory

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Precompute log return series per ticker
        |--------------------------------------------------------------------------
        | Using natural log returns ensures additive stability and numerical
        | safety for small price changes.
        */
        $returnsByTicker = [];
        $datesByTicker   = [];

        foreach ($closesByTicker as $tid => $series) {
            ksort($series); // ensure chronological order
            $dates   = array_keys($series);
            $closes  = array_values($series);

            // Compute log returns
            $returns = $this->logReturns($closes);

            // Align date array to match returns length (returns are N-1)
            $alignedDates = array_slice($dates, 1);

            $returnsByTicker[$tid] = $returns;
            $datesByTicker[$tid]   = $alignedDates;
        }

        unset($closesByTicker);

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Pairwise correlation computation (block-tiled for scalability)
        |--------------------------------------------------------------------------
        | - Iterates in tile blocks to keep memory O(chunk²)
        | - Enforces canonical order (a < b)
        | - Skips pairs with insufficient overlap
        */
        $pairRows = [];
        $totalPairsConsidered = 0;
        $totalPairsWritten = 0;

        for ($aStart = 0; $aStart < $n; $aStart += $chunkSize) {
            $aEnd = min($n, $aStart + $chunkSize);
            $aBlock = array_slice($ids, $aStart, $aEnd - $aStart);

            for ($bStart = $aStart; $bStart < $n; $bStart += $chunkSize) {
                $bEnd = min($n, $bStart + $chunkSize);
                $bBlock = array_slice($ids, $bStart, $bEnd - $bStart);

                foreach ($aBlock as $aid) {
                    foreach ($bBlock as $bid) {
                        // Skip self and duplicates (upper triangle only)
                        if ($bid <= $aid) continue;
                        $totalPairsConsidered++;

                        // Both tickers must have data
                        if (!isset($returnsByTicker[$aid], $returnsByTicker[$bid])) continue;

                        // Align by intersection of date sets
                        $datesA = $datesByTicker[$aid];
                        $datesB = $datesByTicker[$bid];
                        $setB   = array_flip($datesB);
                        $alignedA = [];
                        $alignedB = [];

                        foreach ($datesA as $idxA => $d) {
                            if (isset($setB[$d])) {
                                $alignedA[] = $returnsByTicker[$aid][$idxA];
                                $idxB = $setB[$d];
                                $alignedB[] = $returnsByTicker[$bid][$idxB];
                            }
                        }

                        $m = count($alignedA);
                        if ($m < max($window, $minOverlap)) continue;

                        // Use only the most recent $window observations
                        $sliceA = array_slice($alignedA, -$window);
                        $sliceB = array_slice($alignedB, -$window);

                        // Skip degenerate pairs with zero variance
                        $stdA = $this->stddev($sliceA);
                        $stdB = $this->stddev($sliceB);
                        if ($stdA <= 0 || $stdB <= 0) continue;

                        // Compute correlation / beta / r²
                        $corr = $this->corr($sliceA, $sliceB, $stdA, $stdB);
                        if (!is_finite($corr)) continue;

                        $beta = $this->beta($sliceA, $sliceB);
                        $r2   = $corr * $corr;

                        // Append to batch for bulk upsert
                        $pairRows[] = [
                            'ticker_id_a' => $aid,
                            'ticker_id_b' => $bid,
                            'as_of_date'  => $asOf,
                            'corr'        => round($corr, 6),
                            'beta'        => is_finite($beta) ? round($beta, 6) : null,
                            'r2'          => round($r2, 6),
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ];

                        // Periodic flush to DB
                        if (count($pairRows) >= $flushEvery) {
                            $this->flushUpsert($pairRows);
                            $totalPairsWritten += count($pairRows);
                            $pairRows = [];
                        }
                    }
                }
            }
        }

        // Final flush
        if (!empty($pairRows)) {
            $this->flushUpsert($pairRows);
            $totalPairsWritten += count($pairRows);
        }

        /*
        |--------------------------------------------------------------------------
        | 6️⃣ Logging summary and completion status
        |--------------------------------------------------------------------------
        */
        Log::channel('ingest')->info('✅ Correlation matrix computation complete', [
            'as_of'         => $asOf,
            'tickers'       => $n,
            'pairs_seen'    => $totalPairsConsidered,
            'pairs_written' => $totalPairsWritten,
            'window'        => $window,
            'lookbackDays'  => $lookbackDays,
        ]);
    }

    // =========================================================================
    // 🧩 Database I/O: Bulk Upsert
    // =========================================================================
    /**
     * Efficiently write all correlation results into the database
     * using Laravel’s bulk upsert (MySQL ON DUPLICATE KEY UPDATE).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return void
     */
    protected function flushUpsert(array $rows): void
    {
        DB::table('ticker_correlations')->upsert(
            $rows,
            ['ticker_id_a', 'ticker_id_b', 'as_of_date'],
            ['corr', 'beta', 'r2', 'updated_at']
        );
    }

    // =========================================================================
    // 📊 Math Helpers
    // =========================================================================

    /**
     * Compute natural log returns between consecutive closes.
     *
     * @param  array<float>  $closes
     * @return array<float>
     */
    protected function logReturns(array $closes): array
    {
        $n = count($closes);
        if ($n < 2) return [];
        $out = [];
        for ($i = 1; $i < $n; $i++) {
            $prev = $closes[$i - 1];
            $curr = $closes[$i];
            $out[] = ($prev > 0 && $curr > 0) ? log($curr / $prev) : 0.0;
        }
        return $out;
    }

    /**
     * Compute sample standard deviation.
     */
    protected function stddev(array $x): float
    {
        $n = count($x);
        if ($n < 2) return 0.0;
        $mean = array_sum($x) / $n;
        $ss = 0.0;
        foreach ($x as $v) {
            $d = $v - $mean;
            $ss += $d * $d;
        }
        return sqrt($ss / ($n - 1));
    }

    /**
     * Compute Pearson correlation given precomputed stddevs.
     */
    protected function corr(array $a, array $b, float $stdA, float $stdB): float
    {
        $n = min(count($a), count($b));
        if ($n < 2 || $stdA == 0.0 || $stdB == 0.0) return NAN;

        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;

        $num = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($a[$i] - $meanA) * ($b[$i] - $meanB);
        }

        $den = ($n - 1) * $stdA * $stdB;
        return $den > 0.0 ? $num / $den : NAN;
    }

    /**
     * Compute OLS Beta = Cov(A,B) / Var(B).
     *
     * B is treated as the “reference” series (e.g., market or peer).
     */
    protected function beta(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n < 2) return NAN;

        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;

        $cov = 0.0;
        $varB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $meanA;
            $db = $b[$i] - $meanB;
            $cov += $da * $db;
            $varB += $db * $db;
        }

        if ($varB <= 0.0) return NAN;
        return $cov / $varB;
    }
}