<?php

namespace App\Services\Validation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 *  DataAuditService (v1.1)
 * ============================================================================
 *
 * Adds table-level "Health %" scoring and overall system health summary.
 * ============================================================================
 */
class DataAuditService
{
    /** @var array<string> List of core ticker-related tables to audit */
    protected array $tables = [
        'ticker_price_histories',
        'ticker_indicators',
        'ticker_feature_snapshots',
        'ticker_feature_metrics',
    ];

    public function run(int $limit = 0, bool $detail = false): array
    {
        $summary = ['tables' => [], 'cross' => [], 'overall' => []];

        $totalTickers = DB::table('tickers')->count();
        $healthScores = [];

        // ------------------------------------------------------------------
        // 1️⃣ Table-level record counts + health %
        // ------------------------------------------------------------------
        foreach ($this->tables as $table) {
            $count = DB::table($table)->count();

            // derive approximate ticker coverage ratio
            $tickersWithData = DB::table($table)->distinct('ticker_id')->count('ticker_id');
            $ratio = $totalTickers > 0 ? round(($tickersWithData / $totalTickers) * 100, 2) : 0;

            $status = match (true) {
                $ratio >= 99.5 => 'OK',
                $ratio >= 95   => 'WARN',
                default        => 'ERROR',
            };

            $summary['tables'][$table] = [
                'count'   => $count,
                'tickers_with_data' => $tickersWithData,
                'total_tickers'     => $totalTickers,
                'health_percent'    => $ratio,
                'status'            => $status,
            ];

            $healthScores[] = $ratio;
        }

        // ------------------------------------------------------------------
        // 2️⃣ Cross-table consistency checks
        // ------------------------------------------------------------------
        $summary['cross'] = [
            'Tickers with no price history'      => $this->countMissing('ticker_price_histories'),
            'Tickers missing indicators'         => $this->countMissing('ticker_indicators'),
            'Tickers missing snapshots'          => $this->countMissing('ticker_feature_snapshots'),
            'Tickers missing metrics'            => $this->countMissing('ticker_feature_metrics'),
            'Snapshots without matching metrics' => $this->countOrphans('ticker_feature_snapshots', 'ticker_feature_metrics'),
            'Metrics without matching snapshots' => $this->countOrphans('ticker_feature_metrics', 'ticker_feature_snapshots'),
        ];

        if ($detail) {
            $summary['details'] = [
                'missing_metrics'   => $this->listMissing('ticker_feature_metrics', 25),
                'missing_snapshots' => $this->listMissing('ticker_feature_snapshots', 25),
            ];
        }

        // ------------------------------------------------------------------
        // 3️⃣ Overall Health Score
        // ------------------------------------------------------------------
        $summary['overall']['system_health_percent'] =
            count($healthScores) > 0 ? round(array_sum($healthScores) / count($healthScores), 2) : 0;

        $summary['overall']['grade'] = match (true) {
            $summary['overall']['system_health_percent'] >= 99.5 => 'Excellent',
            $summary['overall']['system_health_percent'] >= 95   => 'Good',
            $summary['overall']['system_health_percent'] >= 90   => 'Fair',
            default => 'Critical',
        };

        Log::channel('ingest')->info('🧩 DataAuditService summary', $summary);
        return $summary;
    }

    // ----------------------------------------------------------------------
    // Helper methods
    // ----------------------------------------------------------------------

    protected function countMissing(string $table): int
    {
        return DB::table('tickers')
            ->leftJoin($table, "$table.ticker_id", '=', 'tickers.id')
            ->whereNull("$table.ticker_id")
            ->count();
    }

    protected function countOrphans(string $left, string $right): int
    {
        return DB::table($left)
            ->leftJoin($right, "$right.ticker_id", '=', "$left.ticker_id")
            ->whereNull("$right.ticker_id")
            ->distinct()
            ->count("$left.ticker_id");
    }

    protected function listMissing(string $table, int $limit = 10): array
    {
        return DB::table('tickers')
            ->leftJoin($table, "$table.ticker_id", '=', 'tickers.id')
            ->whereNull("$table.ticker_id")
            ->limit($limit)
            ->pluck('ticker')
            ->all();
    }
}