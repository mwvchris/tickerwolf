<?php

namespace App\Services\Analytics;

use App\Models\Ticker;
use App\Models\TickerIndicator;
use App\Models\TickerPriceHistory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\Compute\Registry;

/**
 * ============================================================================
 *  FeatureSnapshotBuilder
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Builds **aggregated, JSON-based feature snapshots** representing the full
 *   analytic "state" of a ticker for each trading day. These snapshots power
 *   TickerWolf’s machine-learning feature pipelines and AI inference models.
 *
 * 🧠 Design Overview:
 * ----------------------------------------------------------------------------
 *   A. Inputs:
 *      • Core indicators precomputed & stored in `ticker_indicators` (MACD, ATR, ADX, VWAP, …)
 *      • Snapshot-only indicators computed on-the-fly (e.g., Momentum) — not persisted to core
 *      • Advanced derived analytics via AnalyticsCalculator (Sharpe, Beta, Volatility, Drawdown)
 *
 *   B. Build Flow:
 *      1️⃣ Fetch precomputed indicator rows from `ticker_indicators`
 *      2️⃣ Compute “snapshot-only” indicators locally (e.g., Momentum) from OHLCV bars
 *      3️⃣ Compute derived analytics via `AnalyticsCalculator`
 *      4️⃣ Merge all per-day metrics → complete feature vector
 *      5️⃣ Persist to:
 *          - `ticker_feature_snapshots` (rich JSON blobs)
 *          - `ticker_feature_metrics`   (flat numeric summary table)
 *
 * 💡 Why compute some indicators locally here?
 * ----------------------------------------------------------------------------
 *   • By design, certain indicators (like Momentum) are **not stored** in
 *     `ticker_indicators` (core layer). They belong to the **snapshot layer**.
 *   • Previously this made fields like `momentum_10` appear as `null` in snapshots.
 *   • This class now computes those snapshot-only metrics before merging, so the
 *     JSON and flat metrics are complete.
 *
 * ✅ Logging:
 * ----------------------------------------------------------------------------
 *   • Start/finish logs
 *   • Per-module compute summaries for snapshot-only indicators
 *   • Metrics summary write counts
 *
 * ============================================================================
 */
class FeatureSnapshotBuilder
{
    public function __construct(
        protected AnalyticsCalculator $calc
    ) {}

    /**
     * Build and persist (or preview) feature snapshots for a single ticker.
     *
     * @param  int   $tickerId   The ID of the ticker being processed
     * @param  array $range      Date range in ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD'] format
     * @param  array $params     Optional runtime parameters (per-indicator overrides)
     * @param  bool  $preview    If true, performs a dry-run (no DB writes)
     * @return array{snapshots:int} Summary of total snapshots written or simulated
     */
    public function buildForTicker(int $tickerId, array $range = [], array $params = [], bool $preview = false): array
    {
        /*
        |--------------------------------------------------------------------------
        | 0️⃣ Resolve policy/config and load ticker
        |--------------------------------------------------------------------------
        */
        $storage  = config('indicators.storage');
        $defaults = config('indicators.defaults');

        /** @var Ticker|null $ticker */
        $ticker = Ticker::find($tickerId);
        if (!$ticker) {
            Log::channel('ingest')->warning("⚠️ FeatureSnapshotBuilder aborted: ticker not found", [
                'ticker_id' => $tickerId,
            ]);
            return ['snapshots' => 0];
        }

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Define range and initialization
        |--------------------------------------------------------------------------
        */
        $from = $range['from'] ?? null;
        $to   = $range['to'] ?? null;

        Log::channel('ingest')->info("🚀 Starting FeatureSnapshotBuilder", [
            'ticker_id' => $tickerId,
            'range'     => $range,
            'preview'   => $preview,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Fetch precomputed core indicator rows (from ticker_indicators)
        |--------------------------------------------------------------------------
        | These are the high-value, stored-daily indicators (MACD, ATR, ADX, VWAP).
        */
        $coreQ = TickerIndicator::query()
            ->where('ticker_id', $tickerId)
            ->where('resolution', '1d');

        if ($from) $coreQ->where('t', '>=', $from);
        if ($to)   $coreQ->where('t', '<=', $to);

        $coreRows = $coreQ->orderBy('t', 'asc')
            ->get(['t', 'indicator', 'value', 'meta']);

        if ($coreRows->isEmpty()) {
            Log::channel('ingest')->warning("⚠️ No core indicator data found for ticker", [
                'ticker_id' => $tickerId,
                'range'     => $range,
            ]);
            return ['snapshots' => 0];
        }

        // Group core rows by date with safe meta decoding
        $grouped = [];
        foreach ($coreRows as $row) {
            $key = Carbon::parse($row->t)->toDateString();

            // Normalize meta field
            $metaValue = $row->meta;
            if (is_array($metaValue)) {
                $meta = $metaValue;
            } elseif (is_string($metaValue) && $metaValue !== '') {
                $meta = json_decode($metaValue, true);
            } else {
                $meta = null;
            }

            $grouped[$key][$row->indicator] = [
                'value' => is_null($row->value) ? null : (float) $row->value,
                'meta'  => $meta,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Compute snapshot-only indicators locally (e.g., Momentum)
        |--------------------------------------------------------------------------
        | We load OHLCV bars once and run only the indicators that are configured
        | to live in the snapshot layer but are *not* part of the core layer.
        |
        | • snapshotSet = storage['ticker_feature_snapshots']
        | • coreSet     = storage['ticker_indicators']
        | • derivedSet  = computed by AnalyticsCalculator: beta, sharpe, volatility, drawdown
        | • computeSet  = snapshotSet - coreSet - derivedSet  (e.g., 'momentum')
        */
        $snapshotSet = (array)($storage['ticker_feature_snapshots'] ?? []);
        $coreSet     = (array)($storage['ticker_indicators']        ?? []);
        $derivedSet  = ['beta', 'sharpe', 'volatility', 'drawdown']; // handled by AnalyticsCalculator

        $computeSet  = array_values(array_diff($snapshotSet, $coreSet, $derivedSet));
        if (!empty($computeSet)) {
            // 3a. Load bars (only once)
            $barsQ = TickerPriceHistory::query()
                ->where('ticker_id', $tickerId)
                ->where('resolution', '1d');

            // Extend the "from" window back by a buffer to satisfy lookbacks (e.g., momentum_10)
            // We'll subtract ~90 days as a safe buffer; DB will clamp if not available.
            if ($from) {
                $fromBuf = Carbon::parse($from)->subDays(90)->toDateString();
                $barsQ->where('t', '>=', $fromBuf);
            }
            if ($to) {
                $barsQ->where('t', '<=', $to);
            }

            $bars = $barsQ->orderBy('t', 'asc')
                ->get(['t','o','h','l','c','v','vw'])
                ->map(fn($row) => [
                    't'  => Carbon::parse($row->t)->toDateTimeString(),
                    'o'  => (float) $row->o,
                    'h'  => (float) $row->h,
                    'l'  => (float) $row->l,
                    'c'  => (float) $row->c,
                    'v'  => (float) $row->v,
                    'vw' => (float) $row->vw,
                ])
                ->values()
                ->all();

            if (empty($bars)) {
                Log::channel('ingest')->warning("⚠️ No OHLCV bars found for snapshot-only compute", [
                    'ticker' => $ticker->ticker,
                    'range'  => $range,
                    'set'    => $computeSet,
                ]);
            } else {
                // 3b. Resolve and run the snapshot-only modules
                $modules = Registry::select($computeSet);

                if (empty($modules)) {
                    Log::channel('ingest')->warning("⚠️ No snapshot-only indicator modules resolved", [
                        'ticker' => $ticker->ticker,
                        'set'    => $computeSet,
                    ]);
                } else {
                    foreach ($modules as $module) {
                        // Merge config defaults + runtime overrides
                        $modParams = array_replace_recursive(
                            $defaults[$module->name] ?? [],
                            $params[$module->name]   ?? []
                        );

                        // Normalize 'period' → 'window' and 'windows' when appropriate
                        if (isset($modParams['period']) && !isset($modParams['window'])) {
                            $modParams['window'] = $modParams['period'];
                        }
                        if (isset($modParams['window']) && !isset($modParams['windows'])) {
                            $modParams['windows'] = [$modParams['window']];
                        }

                        $rows = $module->compute($bars, $modParams);

                        Log::channel('ingest')->info("🧮 Snapshot-only module computed", [
                            'ticker' => $ticker->ticker,
                            'module' => $module->name,
                            'rows'   => is_array($rows) ? count($rows) : 0,
                            'params' => $modParams,
                        ]);

                        // Route into the grouped-per-day structure, respecting the user date range
                        foreach ($rows as $r) {
                            $t = isset($r['t']) ? Carbon::parse($r['t'])->toDateString() : null;
                            if (!$t) continue;

                            // Clamp final day to requested window
                            if ($from && $t < $from) continue;
                            if ($to   && $t > $to)   continue;

                            if (!isset($grouped[$t])) {
                                $grouped[$t] = [];
                            }

                            $grouped[$t][$r['indicator']] = [
                                'value' => $r['value'],
                                'meta'  => $r['meta'],
                            ];
                        }
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Compute derived analytics (Sharpe, Beta, Volatility, Drawdown, etc.)
        |--------------------------------------------------------------------------
        | The AnalyticsCalculator handles heavier computations. It returns a map:
        |   [ 'YYYY-MM-DD' => ['sharpe_60' => ['value'=>...], 'drawdown' => ...], ... ]
        */
        $derived = $this->calc->computeDerivedAnalytics($tickerId, $from, $to);

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Merge base + snapshot-only + derived into final snapshots
        |--------------------------------------------------------------------------
        | For each date in the union, merge all metric maps into one.
        */
        $allDates = array_unique(array_merge(array_keys($grouped), array_keys($derived)));
        sort($allDates);

        $rows = [];
        foreach ($allDates as $date) {
            // Only produce snapshots within requested [from, to]
            if ($from && $date < $from) continue;
            if ($to   && $date > $to)   continue;

            $base    = $grouped[$date] ?? [];
            $derivedForDay = $derived[$date] ?? [];

            // Derived entries are already in indicator-keyed form
            $snapshot = $base;
            foreach ($derivedForDay as $k => $payload) {
                $snapshot[$k] = $payload; // ['value'=>..., 'meta'=>...]
            }

            $rows[] = [
                'ticker_id'  => $tickerId,
                't'          => $date,
                'indicators' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 6️⃣ Persist results to the database (or simulate in preview mode)
        |--------------------------------------------------------------------------
        | Writes both JSON snapshots and flattened numeric metrics for fast querying.
        */
        $count = 0;

        if (! $preview) {
            // ✅ Write serialized feature snapshots
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('ticker_feature_snapshots')->upsert(
                    $chunk,
                    ['ticker_id', 't'],
                    ['indicators', 'updated_at']
                );
                $count += count($chunk);
            }

            // ✅ Extract key flat metrics for summary analytics
            // NOTE: We keep parity with your prior schema fields.
            $flatRows = [];
            foreach ($rows as $r) {
                $data = json_decode($r['indicators'], true);

                $flatRows[] = [
                    'ticker_id'     => $tickerId,
                    't'             => $r['t'],
                    'sharpe_60'     => $data['sharpe_60']['value']     ?? null,
                    'volatility_30' => $data['volatility_30']['value'] ?? null,
                    'drawdown'      => $data['drawdown']['value']      ?? null,
                    'beta_60'       => $data['beta_60']['value']       ?? null,
                    'momentum_10'   => $data['momentum_10']['value']   ?? null, // ← now filled
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }

            foreach (array_chunk($flatRows, 1000) as $chunk) {
                DB::table('ticker_feature_metrics')->upsert(
                    $chunk,
                    ['ticker_id', 't'],
                    ['sharpe_60', 'volatility_30', 'drawdown', 'beta_60', 'momentum_10', 'updated_at']
                );
            }

            Log::channel('ingest')->info("📊 Metrics summary upserted", [
                'ticker_id' => $tickerId,
                'rows'      => count($flatRows),
            ]);
        } else {
            // 💡 Dry-run (preview mode)
            $count = count($rows);
            Log::channel('ingest')->info("💡 Preview mode — computed {$count} snapshot(s) for ticker {$tickerId}");
        }

        /*
        |--------------------------------------------------------------------------
        | 7️⃣ Logging summary
        |--------------------------------------------------------------------------
        */
        Log::channel('ingest')->info("✅ Feature snapshots built successfully", [
            'ticker_id' => $tickerId,
            'snapshots' => $count,
            'preview'   => $preview,
        ]);

        return ['snapshots' => $count];
    }
}