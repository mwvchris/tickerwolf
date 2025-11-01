<?php

namespace App\Services\Compute;

use App\Models\Ticker;
use App\Models\TickerPriceHistory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ============================================================================
 *  FeaturePipeline
 * ============================================================================
 *
 * 🔧 Purpose:
 *   The FeaturePipeline acts as the **hybrid computation orchestrator** for
 *   TickerWolf’s analytical engine. It coordinates:
 *     • Retrieval of OHLCV bars (daily price/volume data)
 *     • Execution of indicator modules (SMA, MACD, Momentum, etc.)
 *     • Intelligent routing of results to storage layers:
 *         - Core DB layer      → ticker_indicators
 *         - Snapshot JSON layer→ ticker_feature_snapshots
 *         - Cache layer        → in-memory via Laravel Cache
 *
 * 🧠 Design Philosophy:
 *   - Each indicator is computed **once per run**; outputs are routed to all
 *     relevant destinations (DB, cache, snapshot) according to config policy.
 *   - The pipeline dynamically adapts to indicator definitions declared in
 *     `config/indicators.php`.
 *   - Multi-window indicators (e.g., SMA_50, Momentum_10) are auto-parsed.
 *   - The system enforces consistent parameter normalization (`period` ⇄ `window`).
 *
 * ⚙️ Invocation Contexts:
 *   - Called by `BuildTickerSnapshotJob` / `TickersComputeIndicators` / others.
 *   - Safe for parallel execution inside Bus::batch().
 *
 * ============================================================================
 */
class FeaturePipeline
{
    /**
     * Execute the hybrid computation pipeline for a single ticker.
     *
     * @param  int            $tickerId        Database ID of the ticker
     * @param  array<string>  $indicatorNames  Optional list of indicator names
     * @param  array{from?:string,to?:string} $range Date range filter (ISO8601)
     * @param  array          $params          Per-module runtime parameter overrides
     * @param  bool           $writeCoreToDb   Persist outputs to ticker_indicators
     * @param  bool           $buildSnapshots  Aggregate and persist JSON snapshots
     * @param  bool           $primeCache      Cache “cache_only” indicators in Redis/file
     * @return array{inserted:int,snapshots:int,cache:int} Summary of operation counts
     */
    public function runForTicker(
        int $tickerId,
        array $indicatorNames = [],
        array $range = [],
        array $params = [],
        bool $writeCoreToDb = true,
        bool $buildSnapshots = false,
        bool $primeCache = false
    ): array {
        /*
        |--------------------------------------------------------------------------
        | 1. Ticker validation
        |--------------------------------------------------------------------------
        */
        $ticker = Ticker::find($tickerId);
        if (!$ticker) {
            Log::channel('ingest')->warning('⚠️ FeaturePipeline aborted: ticker not found', [
                'ticker_id' => $tickerId,
            ]);
            return ['inserted' => 0, 'snapshots' => 0, 'cache' => 0];
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Load indicator configuration policy
        |--------------------------------------------------------------------------
        | This configuration drives the entire routing logic. Each section in
        | config/indicators.php defines where indicator results should be stored.
        */
        $storage   = config('indicators.storage');
        $defaults  = config('indicators.defaults');
        $cacheTtl  = (int) (config('indicators.cache_ttl.cache_only') ?? 86400);

        $coreSet     = $storage['ticker_indicators']        ?? ['macd','atr','adx','vwap'];
        $snapshotSet = $storage['ticker_feature_snapshots'] ?? [];
        $cacheSet    = $storage['cache_only']               ?? [];
        $onDemandSet = $storage['on_demand']                ?? [];

        /*
        |--------------------------------------------------------------------------
        | 3. Determine which indicators to compute
        |--------------------------------------------------------------------------
        | If no explicit list is passed, we compute the full union of all tiers.
        */
        if (empty($indicatorNames)) {
            $indicatorNames = array_values(array_unique(array_merge(
                $coreSet, $snapshotSet, $cacheSet, $onDemandSet
            )));
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Parse indicator names (supporting suffix patterns)
        |--------------------------------------------------------------------------
        | Handles flexible module naming such as:
        |   "sma_50"      → SMA with window=50
        |   "momentum_10" → Momentum with window=10
        |   "ema_200"     → EMA with window=200
        | Also normalizes `period` ⇄ `window` for cross-module compatibility.
        */
        $parsedIndicators = [];
        foreach ($indicatorNames as $name) {
            $name = trim($name);
            $paramsForThis = $params;

            if (preg_match('/^([a-zA-Z]+)_(\d{1,4})$/', $name, $m)) {
                $base   = strtolower($m[1]);
                $suffix = (int) $m[2];
                $paramsForThis[$base]['window'] = $suffix;
                $paramsForThis[$base]['period'] = $suffix; // normalized alias
                $parsedIndicators[] = [
                    'name'   => $base,
                    'params' => $paramsForThis[$base] ?? [],
                    'alias'  => $name,
                ];
            } else {
                $base = strtolower($name);
                $parsedIndicators[] = [
                    'name'   => $base,
                    'params' => $params[$base] ?? [],
                    'alias'  => $name,
                ];
            }
        }

        // Debug: show exactly what we intend to compute, and with what params.
        Log::channel('ingest')->info('🔎 FeaturePipeline parsed indicators', [
            'parsed' => array_map(fn($p) => [
                'name'   => $p['name'],
                'alias'  => $p['alias'],
                'params' => $p['params'],
            ], $parsedIndicators),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. Resolve active indicator modules
        |--------------------------------------------------------------------------
        | Registry::select dynamically maps module names → instantiated classes.
        */
        $moduleNames = array_unique(array_map(fn($p) => $p['name'], $parsedIndicators));
        Log::channel('ingest')->info('🧩 FeaturePipeline selecting modules', [
            'requested' => $moduleNames,
        ]);
        $modules = Registry::select($moduleNames);
        Log::channel('ingest')->info('✅ Registry selected modules', [
            'count' => count($modules),
            'names' => array_map(fn($m) => $m->name, $modules),
        ]);

        if (empty($modules)) {
            Log::channel('ingest')->warning('⚠️ No valid indicator modules resolved', [
                'ticker' => $ticker->ticker,
            ]);
            return ['inserted' => 0, 'snapshots' => 0, 'cache' => 0];
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Load OHLCV bars (daily resolution)
        |--------------------------------------------------------------------------
        */
        $q = TickerPriceHistory::query()
            ->where('ticker_id', $tickerId)
            ->where('resolution', '1d');

        if (!empty($range['from'])) $q->where('t', '>=', $range['from']);
        if (!empty($range['to']))   $q->where('t', '<=', $range['to']);

        $bars = $q->orderBy('t', 'asc')
            ->get(['t', 'o', 'h', 'l', 'c', 'v', 'vw'])
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
            Log::channel('ingest')->warning('⚠️ No OHLCV bars found for ticker', [
                'ticker' => $ticker->ticker,
                'range'  => $range,
            ]);
            return ['inserted' => 0, 'snapshots' => 0, 'cache' => 0];
        }

        Log::channel('ingest')->info('▶️ FeaturePipeline start', [
            'ticker'  => $ticker->ticker,
            'modules' => array_map(fn($m) => $m->name, $modules),
            'range'   => $range,
            'bars'    => count($bars),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 7. Compute indicators and route results
        |--------------------------------------------------------------------------
        | Each indicator module returns rows in the shape:
        |   ['t' => timestamp, 'indicator' => name, 'value' => float|null, 'meta' => mixed]
        */
        $dbRows         = [];
        $snapshotByDate = [];
        $cacheCount     = 0;

        foreach ($parsedIndicators as $pi) {
            $module = collect($modules)->firstWhere('name', $pi['name']);
            if (!$module) {
                Log::channel('ingest')->warning('⚠️ Indicator module missing (skipped)', ['name' => $pi['name']]);
                continue;
            }

            // Merge defaults with runtime params. Normalize period ↔ window.
            $modParams = array_replace_recursive($defaults[$pi['name']] ?? [], $pi['params'] ?? []);
            if (isset($modParams['period']) && !isset($modParams['window'])) {
                $modParams['window'] = $modParams['period'];
            }

            $rows = $module->compute($bars, $modParams);

            // 🔬 Post-compute probe: row count & first row sample (helps debug momentum_10)
            $firstSample = $rows[0] ?? null;
            Log::channel('ingest')->info('🧮 Module computed', [
                'ticker' => $ticker->ticker,
                'module' => $pi['alias'] ?? $pi['name'],
                'rows'   => count($rows),
                'params' => $modParams,
                'sample' => $firstSample,
            ]);

            foreach ($rows as $r) {
                $t = isset($r['t']) ? Carbon::parse($r['t'])->toDateTimeString() : null;
                if (!$t) continue;

                // Snapshot Layer: collect configured indicators into per-day JSON
                if (in_array($pi['name'], $snapshotSet, true)) {
                    $day = substr($t, 0, 10);
                    $snapshotByDate[$day] = $snapshotByDate[$day] ?? [];
                    $snapshotByDate[$day][$r['indicator']] = [
                        'value' => $r['value'],
                        'meta'  => $r['meta'],
                    ];
                }

                // Core DB Layer: write only for explicitly configured core metrics
                if ($writeCoreToDb && in_array($pi['name'], $coreSet, true)) {
                    $dbRows[] = [
                        'ticker_id'  => $ticker->id,
                        'resolution' => '1d',
                        't'          => $t,
                        'indicator'  => $r['indicator'],
                        'value'      => $r['value'],
                        'meta'       => $r['meta'],
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                    ];
                }

                // Cache Layer: compact per-day blob for UI speed
                if ($primeCache && in_array($pi['name'], $cacheSet, true)) {
                    $cacheKey = "tw:ind:{$ticker->id}:{$pi['name']}:{$t}";
                    Cache::put($cacheKey, [
                        't'         => $t,
                        'indicator' => $r['indicator'],
                        'value'     => $r['value'],
                        'meta'      => $r['meta'],
                    ], $cacheTtl);
                    $cacheCount++;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Persist results
        |--------------------------------------------------------------------------
        | - ticker_indicators           → upsert individual indicator records
        | - ticker_feature_snapshots   → upsert aggregated JSON blobs
        */
        $inserted = 0;
        foreach (array_chunk($dbRows, 1000) as $chunk) {
            DB::table('ticker_indicators')->upsert(
                $chunk,
                ['ticker_id', 'resolution', 't', 'indicator'],
                ['value', 'meta', 'updated_at']
            );
            $inserted += count($chunk);
        }

        $snapshots = 0;
        if ($buildSnapshots && !empty($snapshotByDate)) {
            $payload = [];
            $now = now()->toDateTimeString();
            foreach ($snapshotByDate as $day => $kv) {
                $payload[] = [
                    'ticker_id'   => $ticker->id,
                    't'           => "{$day} 00:00:00",
                    'indicators'  => json_encode($kv, JSON_UNESCAPED_SLASHES),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
            foreach (array_chunk($payload, 1000) as $chunk) {
                DB::table('ticker_feature_snapshots')->upsert(
                    $chunk,
                    ['ticker_id', 't'],
                    ['indicators', 'updated_at']
                );
                $snapshots += count($chunk);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 9. Final logging and return summary
        |--------------------------------------------------------------------------
        */
        Log::channel('ingest')->info('✅ FeaturePipeline complete', [
            'ticker' => $ticker->ticker,
            'inserted_core_rows' => $inserted,
            'snapshots_upserted' => $snapshots,
            'cache_primed'       => $cacheCount,
        ]);

        return ['inserted' => $inserted, 'snapshots' => $snapshots, 'cache' => $cacheCount];
    }
}