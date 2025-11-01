<?php

namespace App\Services\Analytics;

use App\Models\Ticker;
use App\Models\TickerCorrelation;
use App\Services\Compute\BaseIndicator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CorrelationMatrixService
 *
 * Generates inter-ticker rolling correlations across all tickers
 * (or within a specified sector/universe).
 *
 * Usage:
 *   (new CorrelationMatrixService())->computeMatrix(lookbackDays: 90);
 */
class CorrelationMatrixService extends BaseAnalytics
{
    /**
     * Compute pairwise correlations between tickers' daily closes.
     *
     * @param int $lookbackDays How many days of data to use.
     * @param int $window Rolling window for correlation (e.g., 20 days).
     */
    public function computeMatrix(int $lookbackDays = 90, int $window = 20): void
    {
        $tickers = Ticker::where('active', true)->get(['id', 'ticker']);
        $priceData = $this->fetchRecentCloses($tickers->pluck('id')->toArray(), $lookbackDays);

        $asOf = now()->toDateString();
        $totalPairs = 0;

        foreach ($tickers as $i => $a) {
            for ($j = $i + 1; $j < count($tickers); $j++) {
                $b = $tickers[$j];
                $totalPairs++;

                $seriesA = $priceData[$a->id] ?? [];
                $seriesB = $priceData[$b->id] ?? [];

                if (count($seriesA) < $window || count($seriesB) < $window) continue;

                // Compute returns
                $retA = $this->returns($seriesA);
                $retB = $this->returns($seriesB);

                // Align and compute rolling correlation
                $corrSeries = $this->rollingCorrelation($retA, $retB, $window);
                $betaSeries = $this->rollingBeta($retA, $retB, $window);

                $lastIdx = count($corrSeries) - 1;
                $corr = $corrSeries[$lastIdx];
                $beta = $betaSeries[$lastIdx];
                $r2   = $corr !== null ? pow($corr, 2) : null;

                if ($corr === null) continue;

                TickerCorrelation::updateOrCreate(
                    [
                        'ticker_id_a' => $a->id,
                        'ticker_id_b' => $b->id,
                        'as_of_date'  => $asOf,
                    ],
                    [
                        'corr' => $corr,
                        'beta' => $beta,
                        'r2'   => $r2,
                    ]
                );
            }
        }

        Log::channel('ingest')->info("✅ Correlation matrix computed", [
            'as_of' => $asOf,
            'pairs' => $totalPairs,
        ]);
    }

    /**
     * Fetch recent closing prices (simple version for now).
     *
     * @return array<int, array<float>> keyed by ticker_id
     */
    protected function fetchRecentCloses(array $tickerIds, int $lookbackDays): array
    {
        $rows = DB::table('ticker_bars')
            ->whereIn('ticker_id', $tickerIds)
            ->where('t', '>=', now()->subDays($lookbackDays))
            ->orderBy('t')
            ->get(['ticker_id', 'c']);

        $data = [];
        foreach ($rows as $r) {
            $data[$r->ticker_id][] = (float)$r->c;
        }
        return $data;
    }
}
