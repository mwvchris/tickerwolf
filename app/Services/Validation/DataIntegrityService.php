<?php

namespace App\Services\Validation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use App\Services\Validation\Validators\PolygonDataValidator;

/**
 * ============================================================================
 *  DataIntegrityService  (v2.1 — Config-Driven + Persistent Upstream Validation)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Performs configurable structural validation of ticker price histories
 *   (duplicates, gaps, flatlines, spikes) and coordinates with
 *   PolygonDataValidator to confirm upstream data availability.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Loads configurable thresholds and weights from config/data_validation.php.
 *   • Detects anomalies and computes severity scores (0–1).
 *   • Aggregates an overall ticker “health score” (1 = perfect, 0 = bad).
 *   • Delegates missing or empty datasets to PolygonDataValidator.
 *   • Always populates `upstream` (Polygon verification) for full coverage.
 *   • Returns unified structured results for commands/loggers.
 *
 * 📦 Example Output:
 *   [
 *     'ticker_id'   => 8255,
 *     'health'      => 0.93,
 *     'severity'    => ['gaps'=>0.02,'flat'=>0.03,'spikes'=>0.01],
 *     'issues'      => ['gaps'=>[...],'flat'=>[...]],
 *     'root_causes' => ['missing_price_data'=>'upstream_empty_response'],
 *     'upstream'    => [
 *         'symbol'=>'AAPL','status'=>200,'found'=>true,
 *         'count'=>55,'polygon_status'=>'DELAYED'
 *     ],
 *   ]
 * ============================================================================
 */
class DataIntegrityService
{
    public function __construct(
        protected ?PolygonDataValidator $polygonValidator = null
    ) {
        $this->polygonValidator = $this->polygonValidator ?? app(PolygonDataValidator::class);
    }

    /**
     * Scan ticker data for local anomalies + upstream validation.
     *
     * @param  int  $tickerId
     * @return array<string,mixed>
     */
    public function scanTicker(int $tickerId): array
    {
        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Load bars
        |--------------------------------------------------------------------------
        */
        $bars = DB::table('ticker_price_histories')
            ->where('ticker_id', $tickerId)
            ->where('resolution', '1d')
            ->orderBy('t', 'asc')
            ->get(['t', 'c'])
            ->map(fn($r) => ['t' => $r->t, 'c' => (float) $r->c])
            ->toArray();

        $cfg = Config::get('data_validation', [
            'gap_days_tolerance'    => 10,
            'flat_streak_tolerance' => 5,
            'spike_threshold'       => 0.5,
            'weights'               => ['gaps'=>0.4,'flat'=>0.3,'spikes'=>0.3],
            'min_bars'              => 10,
        ]);

        $result = [
            'ticker_id'   => $tickerId,
            'issues'      => [],
            'root_causes' => [],
            'upstream'    => null,
            'health'      => 1.0,
            'severity'    => [],
        ];

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Handle insufficient local data
        |--------------------------------------------------------------------------
        */
        if (count($bars) < $cfg['min_bars']) {
            Log::channel('ingest')->warning('⚠️ Insufficient local bars', ['ticker_id' => $tickerId]);

            $symbol = DB::table('tickers')->where('id', $tickerId)->value('ticker');
            if ($symbol) {
                $validation = $this->polygonValidator->validateTicker($tickerId, $symbol);
                $result = array_merge($result, $validation);
            }

            $result['status'] = 'insufficient';
            $result['health'] = 0.0;
            return $result;
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Local anomaly detection
        |--------------------------------------------------------------------------
        */
        $issues = ['duplicates'=>[], 'gaps'=>[], 'flat'=>[], 'spikes'=>[]];
        $dates  = array_column($bars, 't');
        $closes = array_column($bars, 'c');
        $total  = count($closes);

        // Duplicates
        $dupes = collect($bars)->groupBy('t')->filter(fn($g) => $g->count() > 1)->keys()->all();
        if ($dupes) $issues['duplicates'] = $dupes;

        // Gaps
        for ($i = 1; $i < $total; $i++) {
            $diff = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
            if ($diff > $cfg['gap_days_tolerance']) {
                $issues['gaps'][] = ['from'=>$dates[$i - 1],'to'=>$dates[$i],'days'=>$diff];
            }
        }

        // Flatlines + Spikes
        for ($i = 1; $i < $total; $i++) {
            $prev = $closes[$i - 1];
            $curr = $closes[$i];
            $ret  = $prev > 0 ? ($curr - $prev) / $prev : 0.0;

            if ($curr == $prev) {
                $issues['flat'][] = $dates[$i];
            } elseif (abs($ret) > $cfg['spike_threshold']) {
                $issues['spikes'][] = ['date'=>$dates[$i],'return'=>round($ret,2)];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Severity scoring & health aggregation
        |--------------------------------------------------------------------------
        */
        $weights  = $cfg['weights'];
        $severity = [];

        foreach (['gaps','flat','spikes'] as $key) {
            $count = count($issues[$key]);
            $severity[$key] = min(1.0, $count / max(1, $total)) * ($weights[$key] ?? 0);
        }

        $totalSeverity = array_sum($severity);
        $health = max(0.0, round(1 - $totalSeverity, 4));

        $result['issues']   = array_filter($issues, fn($v)=>!empty($v));
        $result['severity'] = $severity;
        $result['health']   = $health;
        $result['status']   = $health < 0.9 ? 'warning' : 'success';

        /*
        |--------------------------------------------------------------------------
        | 4.5️⃣ Conditionally populate Polygon upstream data
        |--------------------------------------------------------------------------
        | ✅ Only fetch live upstream info if local data looks degraded
        |    (health < 0.9 or insufficient bars). This avoids 12 000 HTTP calls.
        */
        try {
            if ($health < 0.9 || count($bars) < ($cfg['min_bars'] ?? 10)) {
                $symbol = DB::table('tickers')->where('id', $tickerId)->value('ticker');
                if ($symbol) {
                    $live = $this->polygonValidator->verifyTickerUpstream($symbol);
                    $result['upstream'] = $live;
                    Log::channel('ingest')->debug('🧩 Upstream populated', [
                        'ticker_id' => $tickerId,
                        'symbol'    => $symbol,
                        'resultsCount' => $live['count'] ?? null,
                        'status'    => $live['status'] ?? null,
                        'polygon_status' => $live['polygon_status'] ?? null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('ingest')->error('❌ Upstream verification failed', [
                'ticker_id' => $tickerId,
                'error'     => $e->getMessage(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Integrate Polygon upstream verification if data missing entirely
        |--------------------------------------------------------------------------
        */
        if (empty($bars)) {
            $symbol = DB::table('tickers')->where('id', $tickerId)->value('ticker');
            if ($symbol) {
                $validation = $this->polygonValidator->validateTicker($tickerId, $symbol);
                $result = array_merge($result, $validation);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 6️⃣ Logging + Return
        |--------------------------------------------------------------------------
        */
        if ($health < 0.9) {
            Log::channel('ingest')->warning('⚠️ Data integrity warning', [
                'ticker_id' => $tickerId,
                'health'    => $health,
                'severity'  => $severity,
                'issues'    => array_keys($result['issues']),
            ]);
        }

        return $result;
    }
}