<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\Validation\DataIntegrityService;
use App\Services\Validation\Validators\PolygonDataValidator;
use App\Models\DataValidationLog;
use Carbon\Carbon;

/**
 * ============================================================================
 *  tickers:integrity-scan  (v3.0.0 — Diagnostic Expansion + Deep Health Metrics)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Performs configurable, severity-weighted integrity scans across ticker data,
 *   combining local anomaly scoring with optional live Polygon.io verification.
 *   Now includes detailed per-ticker diagnostics (bar count, coverage ratio,
 *   flatness, average volume, first/last bar dates) and aggregate issue summaries.
 *
 * 🚀 New in v3.0.0:
 * ----------------------------------------------------------------------------
 *   • Extended diagnostic table (Bars, Expected, Coverage%, AvgVol, Issues)
 *   • Inline classification of "empty", "sparse", "flat", "partial", "illiquid"
 *   • Aggregate diagnostic summary (counts by issue type)
 *   • Retains bulk re-ingestion and live verification (v2.6.4 feature set)
 *   • Structured logging of all scan + verification actions
 *
 * ============================================================================
 */
class TickersIntegrityScanCommand extends Command
{
    protected $signature = 'tickers:integrity-scan
                            {--limit=100 : Number of tickers to scan}
                            {--from-id=0 : Start scanning from this ticker ID}
                            {--verify-live : Recheck all critical tickers directly against Polygon.io}';

    protected $description = 'Perform configurable, severity-scored integrity checks for ticker data, with config-driven re-ingest parameters and optional live verification.';

    public function handle(): int
    {
        $limit      = (int) $this->option('limit');
        $fromId     = (int) $this->option('from-id');
        $verifyLive = (bool) $this->option('verify-live');

        $this->info("🧩 Starting integrity scan for {$limit} tickers (from ID {$fromId})…");
        if ($verifyLive) {
            $this->warn('🌐 Live verification mode enabled — Polygon API will be queried for critical tickers.');
        }

        Log::channel('ingest')->info('🧩 tickers:integrity-scan started', [
            'limit'       => $limit,
            'fromId'      => $fromId,
            'verify_live' => $verifyLive,
        ]);

        $startedAt = now();

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Create persistent log entry
        |--------------------------------------------------------------------------
        */
        $log = DataValidationLog::create([
            'entity_type'  => 'ticker_integrity',
            'command_name' => 'tickers:integrity-scan',
            'status'       => 'running',
            'started_at'   => $startedAt,
            'initiated_by' => get_current_user() ?: 'system',
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Initialize service + ticker selection
        |--------------------------------------------------------------------------
        */
        $service = new DataIntegrityService();
        $tickers = DB::table('tickers')
            ->where('id', '>=', $fromId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'ticker', 'type']);

        $results = [];
        $healths = [];
        $sourceHealth = [];

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Per-ticker scans (local + upstream) + diagnostics
        |--------------------------------------------------------------------------
        */
        foreach ($tickers as $ticker) {
            $id     = $ticker->id;
            $symbol = $ticker->ticker;
            $type   = $ticker->type ?? 'CS';

            try {
                $r = $service->scanTicker($id);
                $health = $r['health'] ?? 0;
                $results[$id] = $r;
                $healths[] = $health;

                // Diagnostics
                $bars = DB::table('ticker_price_histories')
                    ->where('ticker_id', $id)
                    ->count();

                $minDate = DB::table('ticker_price_histories')
                    ->where('ticker_id', $id)
                    ->min('t');

                $maxDate = DB::table('ticker_price_histories')
                    ->where('ticker_id', $id)
                    ->max('t');

                $expected = 0;
                if ($minDate && $maxDate) {
                    $expected = Carbon::parse($minDate)->diffInDays(Carbon::parse($maxDate));
                }

                $coverage = $expected > 0 ? round(($bars / $expected) * 100, 2) : 0;
                $avgVol = DB::table('ticker_price_histories')
                    ->where('ticker_id', $id)
                    ->avg('v');

                $flatBars = DB::table('ticker_price_histories')
                    ->where('ticker_id', $id)
                    ->whereColumn('o', '=', 'c')
                    ->whereColumn('h', '=', 'l')
                    ->count();

                $flags = [];
                if ($bars == 0) $flags[] = 'empty';
                elseif ($bars < 10) $flags[] = 'sparse';
                elseif ($coverage < 25) $flags[] = 'partial';
                if ($avgVol !== null && $avgVol < 100) $flags[] = 'illiquid';
                if ($flatBars > 0 && $bars > 0 && ($flatBars / $bars) > 0.5) $flags[] = 'flat';

                $results[$id]['diagnostics'] = [
                    'bars'      => $bars,
                    'expected'  => $expected,
                    'coverage'  => $coverage,
                    'avg_vol'   => $avgVol,
                    'first'     => $minDate ? Carbon::parse($minDate)->format('Y-m-d') : '—',
                    'last'      => $maxDate ? Carbon::parse($maxDate)->format('Y-m-d') : '—',
                    'issues'    => implode(', ', $flags) ?: 'none',
                ];

                if (!empty($r['upstream'])) {
                    $sourceHealth[$id] = $r['upstream'];
                }

                if (isset($r['root_causes']['missing_price_data'])
                    && $r['root_causes']['missing_price_data'] === 'upstream_empty_response') {
                    DB::table('tickers')->where('id', $id)->update([
                        'is_active_polygon'   => false,
                        'deactivation_reason' => 'no_data_from_polygon',
                        'updated_at'          => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                $results[$id] = ['error' => $e->getMessage()];
                $healths[] = 0;
                Log::channel('ingest')->error("❌ Integrity scan failed for ticker {$id}", [
                    'message' => $e->getMessage(),
                    'trace'   => substr($e->getTraceAsString(), 0, 400),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Aggregate overall health stats
        |--------------------------------------------------------------------------
        */
        $total  = count($tickers);
        $avg    = round(array_sum($healths) / max(1, $total), 4);
        sort($healths);
        $median = $healths[intdiv($total, 2)] ?? 0;

        $bands = [
            'healthy'  => count(array_filter($healths, fn($h) => $h >= 0.9)),
            'moderate' => count(array_filter($healths, fn($h) => $h >= 0.6 && $h < 0.9)),
            'critical' => count(array_filter($healths, fn($h) => $h < 0.6)),
        ];

        $status = $bands['critical'] > 0 ? 'warning' : 'success';

        $payload = [
            'total_entities'     => $total,
            'validated_count'    => $bands['healthy'],
            'missing_count'      => $bands['critical'],
            'status'             => $status,
            'completed_at'       => now(),
            'data_source_health' => json_encode($sourceHealth, JSON_UNESCAPED_SLASHES),
            'details'            => json_encode($results, JSON_UNESCAPED_SLASHES),
        ];
        if (Schema::hasColumn('data_validation_logs', 'validation_ratio')) {
            $payload['validation_ratio'] = round($bands['healthy'] / max(1, $total), 4);
        }
        $log->update($payload);

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Summary display (high-level bands)
        |--------------------------------------------------------------------------
        */
        $this->line('');
        $this->info('📊 Health Distribution:');
        $this->line("   ✅ Healthy  : {$bands['healthy']}");
        $this->line("   ⚠️  Moderate : {$bands['moderate']}");
        $this->line("   🔴 Critical : {$bands['critical']}");
        $this->line("   ———————————————————————————————");
        $this->line("   Avg Health : {$avg}");
        $this->line("   Median     : {$median}");
        $this->line('');

        /*
        |--------------------------------------------------------------------------
        | 6️⃣ Detailed diagnostics table (Moderate + Critical)
        |--------------------------------------------------------------------------
        */
        $flagged = [];
        foreach ($results as $id => $r) {
            $h = $r['health'] ?? 1;
            if ($h >= 0.9) continue;

            $d = $r['diagnostics'] ?? [];
            $flagged[] = [
                'id'        => $id,
                'symbol'    => DB::table('tickers')->where('id', $id)->value('ticker'),
                'type'      => DB::table('tickers')->where('id', $id)->value('type'),
                'health'    => $h,
                'severity'  => $h < 0.6 ? 'Critical' : 'Moderate',
                'bars'      => $d['bars'] ?? 0,
                'expected'  => $d['expected'] ?? 0,
                'coverage'  => $d['coverage'] ?? 0,
                'first'     => $d['first'] ?? '—',
                'last'      => $d['last'] ?? '—',
                'avg_vol'   => $d['avg_vol'] ?? 0,
                'issues'    => $d['issues'] ?? 'none',
            ];
        }

        if ($flagged) {
            usort($flagged, fn($a, $b) =>
                ($a['severity'] === $b['severity'])
                    ? ($a['id'] <=> $b['id'])
                    : (($a['severity'] === 'Critical') ? -1 : 1)
            );

            $this->warn("⚠️ Detailed Report (Moderate & Critical):");
            $this->line(str_pad('ID', 8)
                . str_pad('Symbol', 10)
                . str_pad('Type', 10)
                . str_pad('Health', 10)
                . str_pad('Severity', 12)
                . str_pad('Bars', 8)
                . str_pad('Expect', 10)
                . str_pad('Cover%', 10)
                . str_pad('First', 12)
                . str_pad('Last', 12)
                . str_pad('AvgVol', 10)
                . "Issues");
            $this->line(str_repeat('-', 128));

            foreach ($flagged as $f) {
                $sevLabel = $f['severity'] === 'Critical'
                    ? $this->formatRed('Critical')
                    : $this->formatYellow('Moderate');

                $this->line(
                    str_pad($f['id'], 8)
                    . str_pad($f['symbol'], 10)
                    . str_pad($f['type'], 10)
                    . str_pad(number_format($f['health'], 3), 10)
                    . str_pad($sevLabel, 14)
                    . str_pad($f['bars'], 8)
                    . str_pad($f['expected'], 10)
                    . str_pad($f['coverage'] . '%', 10)
                    . str_pad($f['first'], 12)
                    . str_pad($f['last'], 12)
                    . str_pad(number_format((float)$f['avg_vol'], 0), 10)
                    . $f['issues']
                );
            }

            $this->newLine();
            // Aggregate issue summary
            $this->info('📈 Diagnostic Summary:');
            $this->line('   - Empty tickers     : ' . collect($flagged)->filter(fn($x) => str_contains($x['issues'], 'empty'))->count());
            $this->line('   - Sparse tickers    : ' . collect($flagged)->filter(fn($x) => str_contains($x['issues'], 'sparse'))->count());
            $this->line('   - Partial coverage  : ' . collect($flagged)->filter(fn($x) => str_contains($x['issues'], 'partial'))->count());
            $this->line('   - Flat tickers      : ' . collect($flagged)->filter(fn($x) => str_contains($x['issues'], 'flat'))->count());
            $this->line('   - Illiquid tickers  : ' . collect($flagged)->filter(fn($x) => str_contains($x['issues'], 'illiquid'))->count());
            $this->newLine();
        }

        /*
        |--------------------------------------------------------------------------
        | 7️⃣ Live verification + bulk re-ingest (unchanged core logic)
        |--------------------------------------------------------------------------
        */
        $actions = [];

        if ($verifyLive && $flagged) {
            $critical = array_filter($flagged, fn($x) => $x['severity'] === 'Critical');
            $this->warn('🔍 Performing live Polygon verification for critical tickers...');

            $validator = new PolygonDataValidator(app('App\Services\Validation\Probes\PolygonProbe'));
            $live = [];

            foreach ($critical as $ct) {
                $symbol = $ct['symbol'];
                $type   = $ct['type'];
                $check  = $validator->verifyTickerUpstream($symbol);
                $live[$symbol] = $check;
                $statusMark = $check['found'] ? '✅' : '❌';
                $this->line("   {$statusMark} {$symbol} ({$type}) → {$check['message']} ({$check['status']})");
                usleep(250_000);
            }

            $log->update(['data_source_health' => json_encode($live, JSON_UNESCAPED_SLASHES)]);
            $this->newLine();

            $minDate = config('polygon.price_history_min_date', '2020-01-01');
            $maxDate = now()->toDateString();
            $resolution = config('polygon.default_timespan', 'day');
            $multiplier = config('polygon.default_multiplier', 1);

            $verified = array_keys(array_filter($live, fn($res) => $res['found'] && $res['status'] == 200));
            $notFound = array_keys(array_filter($live, fn($res) => !$res['found']));

            if ($verified) {
                $this->info("✅ " . (is_array($verified) ? count($verified) : 0) . " tickers verified with Polygon (200 OK).");
                if ($this->confirm('Re-ingest all verified (200) critical tickers at once?', true)) {
                    foreach ($verified as $symbol) {
                        $this->callSilent('polygon:ticker-price-histories:ingest', [
                            '--symbol'     => $symbol,
                            '--resolution' => "{$multiplier}{$resolution[0]}",
                            '--from'       => $minDate,
                            '--to'         => $maxDate,
                            '--limit'      => 1,
                        ]);
                        $actions[$symbol] = [
                            'action'      => 're-ingest',
                            'status'      => 'queued',
                            'from'        => $minDate,
                            'to'          => $maxDate,
                            'resolution'  => $resolution,
                            'checked_at'  => now()->toIso8601String(),
                        ];
                    }
                    $this->info("🚀 Queued re-ingestion for " . count($verified) . " tickers.");
                }
            }

            foreach ($notFound as $symbol) {
                if ($this->confirm("Deactivate {$symbol}? Polygon has no upstream data.", true)) {
                    DB::table('tickers')
                        ->where('ticker', $symbol)
                        ->update([
                            'is_active_polygon'   => false,
                            'deactivation_reason' => 'no_data_from_polygon',
                            'updated_at'          => now(),
                        ]);
                    $actions[$symbol] = [
                        'action'     => 'deactivated',
                        'status'     => 'complete',
                        'checked_at' => now()->toIso8601String(),
                    ];
                }
            }

            if ($actions) {
                $log->update(['actions' => json_encode($actions, JSON_UNESCAPED_SLASHES)]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 🔚 Final summary + structured log
        |--------------------------------------------------------------------------
        */
        Log::channel('ingest')->info('✅ tickers:integrity-scan complete', [
            'bands'       => $bands,
            'avg_health'  => $avg,
            'median'      => $median,
            'status'      => $status,
            'verify_live' => $verifyLive,
            'actions'     => $actions,
        ]);

        return Command::SUCCESS;
    }

    /** 🎨 Console color helpers */
    private function formatRed(string $text): string
    {
        return "\033[1;31m{$text}\033[0m";
    }

    private function formatYellow(string $text): string
    {
        return "\033[1;33m{$text}\033[0m";
    }
}