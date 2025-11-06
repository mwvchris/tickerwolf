<?php

namespace App\Services\Validation\Validators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

/**
 * ============================================================================
 *  SnapshotsValidator  (v1.3 — Optimized for Large Datasets)
 * ============================================================================
 *
 * 🔍 Purpose:
 *   Fast health check for ticker_feature_snapshots with sampling & row limits.
 *
 * ⚙️ Key Improvements:
 *   • Limit rows per ticker (default 300 most recent)
 *   • Lightweight JSON inspection (no full decode)
 *   • Same scoring model as v1.2
 * ============================================================================
 */
class SnapshotsValidator
{
    protected int $rowLimit = 300; // limit per ticker for speed

    public function run(array $context): array
    {
        $tickerId = $context['ticker_id'] ?? null;
        if (!$tickerId) {
            return ['status' => 'error', 'health' => 0.0, 'issues' => ['missing_context' => true]];
        }

        // ---------------------------------------------------------------------
        // Pull limited recent rows
        // ---------------------------------------------------------------------
        $rows = DB::table('ticker_feature_snapshots')
            ->where('ticker_id', $tickerId)
            ->select(['t', 'indicators'])
            ->orderByDesc('t')
            ->limit($this->rowLimit)
            ->get()
            ->reverse(); // restore chronological order

        $count = $rows->count();
        $cfg = Config::get('data_validation.snapshots', [
            'min_rows'           => 10,
            'gap_tolerance_days' => 14,
            'flat_streak_limit'  => 5,
            'spike_threshold'    => 9999,
            'weights'            => [
                'missing' => 0.4,
                'gaps'    => 0.3,
                'flat'    => 0.2,
                'spikes'  => 0.1,
            ],
        ]);

        if ($count === 0) {
            return ['ticker_id' => $tickerId, 'status' => 'error', 'health' => 0.0, 'issues' => ['no_snapshots' => true]];
        }

        if ($count < $cfg['min_rows']) {
            return ['ticker_id' => $tickerId, 'status' => 'insufficient', 'health' => 0.0, 'issues' => ['insufficient_rows' => $count]];
        }

        // ---------------------------------------------------------------------
        // Quick sampling metrics instead of full JSON parse
        // ---------------------------------------------------------------------
        $dates = $rows->pluck('t')->all();
        $missing = 0;
        $flatline = 0;
        $spikes = 0;

        $prevIndicatorHash = null;

        foreach ($rows as $r) {
            // Instead of full decode, just inspect length and numeric pattern
            $json = $r->indicators;
            if (!$json || $json === 'null') {
                $missing++;
                continue;
            }

            // detect flatlines via hash of JSON
            $hash = md5($json);
            if ($hash === $prevIndicatorHash) {
                $flatline++;
            }
            $prevIndicatorHash = $hash;

            // detect spikes by searching numeric tokens over threshold
            if (preg_match_all('/[-+]?\d*\.?\d+/', $json, $m)) {
                foreach ($m[0] as $num) {
                    if (abs((float)$num) > $cfg['spike_threshold']) {
                        $spikes++;
                        break 2;
                    }
                }
            }
        }

        // Frequency gaps
        $gaps = 0;
        for ($i = 1; $i < count($dates); $i++) {
            $diff = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
            if ($diff > $cfg['gap_tolerance_days']) $gaps++;
        }

        // ---------------------------------------------------------------------
        // Compute severity + health
        // ---------------------------------------------------------------------
        $weights = $cfg['weights'];
        $severity = [
            'missing' => min(1.0, $missing / $count) * $weights['missing'],
            'gaps'    => min(1.0, $gaps / $count) * $weights['gaps'],
            'flat'    => min(1.0, $flatline / $count) * $weights['flat'],
            'spikes'  => min(1.0, $spikes / $count) * $weights['spikes'],
        ];

        $health = max(0.0, round(1 - array_sum($severity), 4));
        $status = $health < 0.7 ? 'error' : ($health < 0.9 ? 'warning' : 'success');

        return [
            'ticker_id' => $tickerId,
            'status'    => $status,
            'health'    => $health,
            'issues'    => array_filter([
                'missing_rows' => $missing,
                'gaps'         => $gaps,
                'flatlines'    => $flatline,
                'spikes'       => $spikes,
            ]),
            'severity'  => $severity,
        ];
    }
}