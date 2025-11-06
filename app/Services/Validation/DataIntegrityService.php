<?php

namespace App\Services\Validation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use App\Services\Validation\Validators\PolygonDataValidator;
use App\Services\Validation\Validators\PriceHistoryValidator;
use App\Services\Validation\Validators\IndicatorsValidator;
use App\Services\Validation\Validators\SnapshotsValidator;
use App\Services\Validation\Validators\MetricsValidator;

/**
 * ============================================================================
 *  DataIntegrityService  (v3.0 — Unified Validator Orchestration Layer)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Provides a unified framework for validating ticker-level data integrity
 *   across all critical datasets (price history, indicators, snapshots,
 *   metrics, etc.), while retaining specialized logic for Polygon upstream
 *   checks and severity-based health scoring.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Central registry of all registered validators (config-driven & auto-wired)
 *   • Supports per-validator runs or full-suite execution
 *   • Aggregates results, computes global integrity scores, and logs diagnostics
 *   • Integrates seamlessly with existing PolygonDataValidator
 *
 * 📦 Example Usage:
 * ----------------------------------------------------------------------------
 *   // Run specific validator (micro-level)
 *   app(DataIntegrityService::class)->runValidator('price_history', ['limit' => 250]);
 *
 *   // Run all validators (global integrity sweep)
 *   app(DataIntegrityService::class)->runAll();
 *
 * 📊 Example Output:
 * ----------------------------------------------------------------------------
 *   [
 *     'overall_health' => 0.96,
 *     'validators' => [
 *        'price_history' => ['passed'=>true, 'tickers_failed'=>2, ...],
 *        'indicator'     => ['passed'=>false, ...],
 *     ],
 *     'upstream_checked' => 118,
 *     'issues' => [...],
 *   ]
 * ============================================================================
 */
class DataIntegrityService
{
    /**
     * @var array<string,string> Validator registry map
     */
    protected array $validators = [
        'price_history' => PriceHistoryValidator::class,
        'indicators'    => IndicatorsValidator::class,
        'snapshots'     => SnapshotsValidator::class,
        // 'metrics'      => \App\Services\Validation\Validators\MetricsValidator::class,
    ];

    public function __construct(
        protected ?PolygonDataValidator $polygonValidator = null
    ) {
        $this->polygonValidator = $this->polygonValidator ?? app(PolygonDataValidator::class);
    }

    /**
     * Run all registered validators and produce a unified integrity report.
     *
     * @return array<string,mixed>
     */
    public function runAll(array $options = []): array
    {
        Log::channel('validation')->info('🧩 DataIntegrityService: Running full validation suite');

        $report = [
            'started_at'      => now()->toDateTimeString(),
            'validators_run'  => [],
            'issues'          => [],
            'overall_health'  => 1.0,
            'upstream_checked'=> 0,
        ];

        $healthScores = [];

        foreach ($this->validators as $key => $class) {
            $validator = app($class);
            $result = $validator->run($options);

            $report['validators_run'][$key] = [
                'passed' => $result['passed'] ?? false,
                'summary'=> $result['summary'] ?? [],
                'issues' => $result['issues'] ?? [],
            ];

            if (!empty($result['issues'])) {
                $report['issues'][$key] = $result['issues'];
            }

            // Compute validator-level health score
            $score = $this->calculateHealthFromSummary($result['summary'] ?? []);
            $healthScores[$key] = $score;
        }

        // Aggregate overall integrity health (average across validators)
        $report['overall_health'] = count($healthScores)
            ? round(array_sum($healthScores) / count($healthScores), 4)
            : 1.0;

        $report['completed_at'] = now()->toDateTimeString();

        Log::channel('validation')->info('🏁 DataIntegrityService complete', [
            'overall_health' => $report['overall_health'],
            'validators'     => array_keys($this->validators),
        ]);

        return $report;
    }

    /**
     * Run a specific validator by key.
     *
     * @param  string  $validatorKey
     * @param  array   $options
     * @return array<string,mixed>
     */
    public function runValidator(string $validatorKey, array $options = []): array
    {
        if (!isset($this->validators[$validatorKey])) {
            throw new \InvalidArgumentException("Unknown validator: {$validatorKey}");
        }

        $class = $this->validators[$validatorKey];
        $validator = app($class);

        Log::channel('validation')->info("▶️ Running DataIntegrity validator: {$validatorKey}");

        $result = $validator->run($options);
        $health = $this->calculateHealthFromSummary($result['summary'] ?? []);

        $response = [
            'validator' => $validatorKey,
            'health'    => $health,
            'result'    => $result,
        ];

        Log::channel('validation')->info("🏁 Validator complete: {$validatorKey}", [
            'health' => $health,
            'tickers_failed' => $result['summary']['tickers_failed'] ?? null,
        ]);

        return $response;
    }

    /**
     * Compute a simplified health score from a validator's summary block.
     *
     * @param  array<string,mixed>  $summary
     * @return float
     */
    protected function calculateHealthFromSummary(array $summary): float
    {
        $tested = $summary['tickers_tested'] ?? 0;
        $failed = $summary['tickers_failed'] ?? 0;

        if ($tested === 0) return 1.0;
        $score = max(0.0, 1.0 - ($failed / $tested));

        return round($score, 4);
    }

    /**
     * Perform a single-ticker structural integrity check with optional
     * Polygon upstream validation.
     *
     * This retains your original anomaly-scanning logic for OHLCV data.
     */
    public function scanTicker(int $tickerId): array
    {
        Log::channel('validation')->info('🔍 Running single-ticker integrity scan', ['ticker_id' => $tickerId]);

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

        // Insufficient local data
        if (count($bars) < $cfg['min_bars']) {
            Log::channel('validation')->warning('⚠️ Insufficient local bars', ['ticker_id' => $tickerId]);
            $symbol = DB::table('tickers')->where('id', $tickerId)->value('ticker');

            if ($symbol) {
                $validation = $this->polygonValidator->validateTicker($tickerId, $symbol);
                $result = array_merge($result, $validation);
            }

            $result['status'] = 'insufficient';
            $result['health'] = 0.0;
            return $result;
        }

        // Basic anomaly checks
        $issues = ['duplicates'=>[], 'gaps'=>[], 'flat'=>[], 'spikes'=>[]];
        $dates  = array_column($bars, 't');
        $closes = array_column($bars, 'c');
        $total  = count($closes);

        for ($i = 1; $i < $total; $i++) {
            $prev = $closes[$i - 1];
            $curr = $closes[$i];
            $diffDays = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;

            if ($diffDays > $cfg['gap_days_tolerance']) {
                $issues['gaps'][] = ['from'=>$dates[$i - 1],'to'=>$dates[$i],'days'=>$diffDays];
            }

            if ($curr == $prev) {
                $issues['flat'][] = $dates[$i];
            } elseif ($prev > 0 && abs(($curr - $prev) / $prev) > $cfg['spike_threshold']) {
                $issues['spikes'][] = ['date'=>$dates[$i],'return'=>round(($curr - $prev) / $prev, 2)];
            }
        }

        // Severity scoring
        $weights = $cfg['weights'];
        foreach (['gaps','flat','spikes'] as $key) {
            $count = count($issues[$key]);
            $result['severity'][$key] = min(1.0, $count / max(1, $total)) * ($weights[$key] ?? 0);
        }

        $totalSeverity = array_sum($result['severity']);
        $result['health'] = max(0.0, round(1 - $totalSeverity, 4));
        $result['issues'] = array_filter($issues);

        if ($result['health'] < 0.9) {
            $symbol = DB::table('tickers')->where('id', $tickerId)->value('ticker');
            if ($symbol) {
                try {
                    $result['upstream'] = $this->polygonValidator->verifyTickerUpstream($symbol);
                } catch (\Throwable $e) {
                    Log::channel('validation')->error('❌ Upstream verification failed', [
                        'ticker_id' => $tickerId,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::channel('validation')->info('🏁 scanTicker complete', $result);
        return $result;
    }
}