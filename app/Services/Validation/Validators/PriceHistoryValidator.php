<?php

namespace App\Services\Validation\Validators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

/**
 * ============================================================================
 *  PriceHistoryValidator  (v2.0 — Schema-Aware + Configurable Thresholds)
 * ============================================================================
 *
 * 🔍 Purpose:
 *   Performs structural and statistical integrity validation on the
 *   `ticker_price_histories` table for a given ticker.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Reads compact OHLCV fields (t,o,h,l,c,v) and aliases them to
 *     semantic keys (date, open, high, low, close, volume).
 *   • Detects:
 *       - Missing or insufficient bars
 *       - Duplicated timestamps
 *       - Gaps larger than N days
 *       - Zero-volume or zero-price streaks
 *       - Spike detection (optional)
 *   • Computes a normalized health score between 0 and 1.
 *
 * 📦 Output:
 * ----------------------------------------------------------------------------
 *   [
 *     'ticker_id' => 1234,
 *     'summary' => [
 *         'tickers_tested' => 1,
 *         'tickers_failed' => 0,
 *         'bars' => 1255,
 *         'gaps' => 0,
 *         'flat' => 0,
 *         'duplicates' => 0,
 *     ],
 *     'issues' => [
 *         'gaps' => [...],
 *         'flat' => [...],
 *         'duplicates' => [...],
 *     ],
 *     'health' => 0.995,
 *     'status' => 'success'
 *   ]
 *
 * ============================================================================
 */
class PriceHistoryValidator
{
    /**
     * -------------------------------------------------------------------------
     * Run validator for a given ticker ID.
     * -------------------------------------------------------------------------
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function run(array $params = []): array
    {
        $tickerId = (int) ($params['ticker_id'] ?? 0);

        if ($tickerId <= 0) {
            return [
                'status' => 'error',
                'error'  => 'Invalid ticker_id provided',
                'health' => 0.0,
            ];
        }

        // Load config thresholds (fallback defaults)
        $cfg = Config::get('data_validation', [
            'gap_days_tolerance'    => 10,
            'flat_streak_tolerance' => 5,
            'spike_threshold'       => 0.5,
            'weights'               => ['gaps'=>0.4,'flat'=>0.3,'duplicates'=>0.3],
            'min_bars'              => 10,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Load OHLCV data from ticker_price_histories
        |--------------------------------------------------------------------------
        | Using compact Polygon-style columns and aliasing them for clarity.
        */
        $bars = DB::table('ticker_price_histories')
            ->where('ticker_id', $tickerId)
            ->where('resolution', '1d')
            ->orderBy('t', 'asc')
            ->get([
                't as date',
                'o as open',
                'h as high',
                'l as low',
                'c as close',
                'v as volume',
            ])
            ->map(fn($r) => [
                'date'   => $r->date,
                'open'   => (float) $r->open,
                'high'   => (float) $r->high,
                'low'    => (float) $r->low,
                'close'  => (float) $r->close,
                'volume' => (float) $r->volume,
            ])
            ->toArray();

        $barCount = count($bars);
        $result = [
            'ticker_id' => $tickerId,
            'summary'   => [
                'tickers_tested' => 1,
                'tickers_failed' => 0,
                'bars'           => $barCount,
                'gaps'           => 0,
                'flat'           => 0,
                'duplicates'     => 0,
            ],
            'issues' => [],
            'health' => 1.0,
            'status' => 'success',
        ];

        if ($barCount < $cfg['min_bars']) {
            $result['status'] = 'insufficient';
            $result['health'] = 0.0;
            $result['summary']['tickers_failed'] = 1;
            Log::channel('ingest')->warning('⚠️ Insufficient bars', [
                'ticker_id' => $tickerId,
                'bars'      => $barCount,
            ]);
            return $result;
        }

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Detect anomalies: duplicates, gaps, flats, spikes
        |--------------------------------------------------------------------------
        */
        $issues = ['duplicates'=>[], 'gaps'=>[], 'flat'=>[], 'spikes'=>[]];
        $dates  = array_column($bars, 'date');
        $closes = array_column($bars, 'close');
        $vols   = array_column($bars, 'volume');
        $n      = count($bars);

        // Duplicates
        $dupes = collect($dates)->countBy()->filter(fn($c) => $c > 1)->keys()->all();
        if (!empty($dupes)) {
            $issues['duplicates'] = $dupes;
            $result['summary']['duplicates'] = count($dupes);
        }

        // Gaps (days between consecutive bars)
        for ($i = 1; $i < $n; $i++) {
            $days = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
            if ($days > $cfg['gap_days_tolerance']) {
                $issues['gaps'][] = [
                    'from' => $dates[$i - 1],
                    'to'   => $dates[$i],
                    'days' => $days,
                ];
            }
        }
        $result['summary']['gaps'] = count($issues['gaps']);

        // Flat closes (no price or volume change)
        for ($i = 1; $i < $n; $i++) {
            $prevC = $closes[$i - 1];
            $currC = $closes[$i];
            $prevV = $vols[$i - 1];
            $currV = $vols[$i];

            if ($currC == $prevC || ($currV == 0 && $prevV == 0)) {
                $issues['flat'][] = $dates[$i];
            } elseif ($prevC > 0) {
                $ret = abs(($currC - $prevC) / $prevC);
                if ($ret > $cfg['spike_threshold']) {
                    $issues['spikes'][] = [
                        'date' => $dates[$i],
                        'return' => round($ret, 3),
                    ];
                }
            }
        }

        $result['summary']['flat'] = count($issues['flat']);

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Health scoring
        |--------------------------------------------------------------------------
        */
        $weights = $cfg['weights'];
        $severity = [];

        foreach (['gaps', 'flat', 'duplicates'] as $cat) {
            $count = count($issues[$cat]);
            $severity[$cat] = min(1.0, $count / max(1, $barCount)) * ($weights[$cat] ?? 0);
        }

        $totalSeverity = array_sum($severity);
        $health = max(0.0, round(1 - $totalSeverity, 4));

        $result['issues']   = array_filter($issues, fn($v) => !empty($v));
        $result['health']   = $health;
        $result['severity'] = $severity;
        $result['status']   = $health < 0.9 ? 'warning' : 'success';
        if ($result['status'] !== 'success') {
            $result['summary']['tickers_failed'] = 1;
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Logging
        |--------------------------------------------------------------------------
        */
        if ($result['status'] !== 'success') {
            Log::channel('ingest')->warning('⚠️ PriceHistoryValidator anomaly', [
                'ticker_id' => $tickerId,
                'health'    => $health,
                'issues'    => array_keys($result['issues']),
            ]);
        } else {
            Log::channel('ingest')->info('✅ PriceHistoryValidator passed', [
                'ticker_id' => $tickerId,
                'bars'      => $barCount,
                'health'    => $health,
            ]);
        }

        return $result;
    }
}