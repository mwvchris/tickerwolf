<?php

namespace App\Services\Validation\Validators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

/**
 * ============================================================================
 *  IndicatorsValidator  (v1.0 — Structural + Statistical Health Audit)
 * ============================================================================
 *
 * 🔍 Purpose:
 *   Validate the completeness, consistency, and variability of computed
 *   technical indicators stored in the `ticker_indicators` table.
 *
 * ⚙️ Evaluation Dimensions:
 * ----------------------------------------------------------------------------
 *   1️⃣ Completeness — missing or null indicators per ticker
 *   2️⃣ Flatlines — indicators that remain constant over long periods
 *   3️⃣ Gaps — date gaps where indicator rows should exist but do not
 *   4️⃣ Noise/Range sanity — detect extreme or unrealistic values
 *
 * 📊 Scoring Model:
 * ----------------------------------------------------------------------------
 *   • Each category contributes to an overall severity weight
 *   • Health = 1 - Σ(weighted_severity)
 *   • status = success / warning / insufficient / error
 *
 * 🧩 Example usage:
 *   php artisan tickers:integrity-scan --validator=indicators --limit=100
 *
 * ============================================================================
 */
class IndicatorsValidator
{
    /**
     * Validate indicator data for a single ticker.
     *
     * @param  array  $context  Expected keys: ['ticker_id']
     * @return array<string,mixed>
     */
    public function run(array $context): array
    {
        $tickerId = $context['ticker_id'] ?? null;
        if (!$tickerId) {
            return [
                'status'  => 'error',
                'message' => 'Missing ticker_id in validation context',
                'health'  => 0.0,
                'issues'  => ['missing_context' => true],
            ];
        }

        // ---------------------------------------------------------------------
        // 1️⃣ Load data
        // ---------------------------------------------------------------------
        $rows = DB::table('ticker_indicators')
            ->where('ticker_id', $tickerId)
            ->select(['t', 'indicator', 'value'])
            ->orderBy('t', 'asc')
            ->get();

        $total = $rows->count();
        $cfg = Config::get('data_validation.indicators', [
            'min_rows'          => 10,
            'flat_streak_limit' => 15,
            'gap_tolerance_days'=> 10,
            'spike_threshold'   => 10.0, // overly large indicator values (e.g., RSI > 1000)
            'weights'           => [
                'missing' => 0.4,
                'flat'    => 0.3,
                'gaps'    => 0.2,
                'spikes'  => 0.1,
            ],
        ]);

        $result = [
            'ticker_id'   => $tickerId,
            'status'      => 'success',
            'health'      => 1.0,
            'issues'      => [],
            'severity'    => [],
        ];

        if ($total < $cfg['min_rows']) {
            Log::channel('ingest')->warning('⚠️ Insufficient indicator data', [
                'ticker_id' => $tickerId,
                'rows'      => $total,
            ]);
            return [
                'ticker_id' => $tickerId,
                'status'    => 'insufficient',
                'health'    => 0.0,
                'issues'    => ['insufficient_rows' => $total],
            ];
        }

        // ---------------------------------------------------------------------
        // 2️⃣ Prepare grouped indicators
        // ---------------------------------------------------------------------
        $grouped = $rows->groupBy('indicator');
        $issues = ['missing' => [], 'flat' => [], 'gaps' => [], 'spikes' => []];

        foreach ($grouped as $indicator => $points) {
            $values = $points->pluck('value')->map(fn($v) => (float)$v)->all();
            $dates  = $points->pluck('t')->all();
            $n      = count($values);

            // -- missing/null detection
            if ($n < $cfg['min_rows'] || in_array(null, $values, true)) {
                $issues['missing'][] = $indicator;
            }

            // -- flatline detection
            $flatCount = 0;
            for ($i = 1; $i < $n; $i++) {
                if ($values[$i] === $values[$i - 1]) {
                    $flatCount++;
                } else {
                    $flatCount = 0;
                }
                if ($flatCount >= $cfg['flat_streak_limit']) {
                    $issues['flat'][] = $indicator;
                    break;
                }
            }

            // -- gap detection
            for ($i = 1; $i < $n; $i++) {
                $diffDays = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
                if ($diffDays > $cfg['gap_tolerance_days']) {
                    $issues['gaps'][] = $indicator;
                    break;
                }
            }

            // -- spike/outlier detection
            foreach ($values as $v) {
                if (abs($v) > $cfg['spike_threshold']) {
                    $issues['spikes'][] = $indicator;
                    break;
                }
            }
        }

        // ---------------------------------------------------------------------
        // 3️⃣ Compute severity & health
        // ---------------------------------------------------------------------
        $weights = $cfg['weights'];
        $severity = [];

        foreach (['missing','flat','gaps','spikes'] as $type) {
            $count = count(array_unique($issues[$type]));
            $severity[$type] = min(1.0, $count / max(1, count($grouped))) * ($weights[$type] ?? 0);
        }

        $totalSeverity = array_sum($severity);
        $health = max(0.0, round(1 - $totalSeverity, 4));

        $status = 'success';
        if ($health < 0.9) {
            $status = 'warning';
        }
        if (count($issues['missing']) > 0 && $health < 0.7) {
            $status = 'error';
        }

        // ---------------------------------------------------------------------
        // 4️⃣ Log + return structured result
        // ---------------------------------------------------------------------
        if ($status !== 'success') {
            Log::channel('ingest')->warning('⚠️ IndicatorsValidator anomalies detected', [
                'ticker_id' => $tickerId,
                'health'    => $health,
                'issues'    => array_keys(array_filter($issues, fn($v)=>!empty($v))),
            ]);
        }

        $result['status']   = $status;
        $result['health']   = $health;
        $result['issues']   = array_filter($issues, fn($v)=>!empty($v));
        $result['severity'] = $severity;

        return $result;
    }
}