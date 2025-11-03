<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\Validation\DataIntegrityService;
use App\Services\Validation\Validators\PolygonDataValidator;
use App\Models\DataValidationLog;

/**
 * ============================================================================
 *  tickers:integrity-scan  (v2.6.4 — Bulk Polygon Re-Ingest Support)
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Performs configurable, severity-weighted integrity scans across ticker data,
 *   combining local anomaly scoring with optional live Polygon.io verification.
 *   Now supports one-click “Yes to All” re-ingestion for verified (200) tickers.
 *
 * 🚀 New in v2.6.4:
 * ----------------------------------------------------------------------------
 *   • Added "bulk yes-to-all" prompt after live verification.
 *   • Automatically queues re-ingestion for all verified tickers if confirmed.
 *   • Preserves individual deactivation confirmations for not-found tickers.
 *   • Enhanced structured logging of bulk actions.
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
            ->pluck('id')
            ->toArray();

        $results      = [];
        $sourceHealth = [];
        $healths      = [];

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Per-ticker scans (local + upstream)
        |--------------------------------------------------------------------------
        */
        foreach ($tickers as $id) {
            try {
                $r = $service->scanTicker($id);
                $results[$id] = $r;
                $healths[]    = $r['health'] ?? 0;

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
        | 5️⃣ Summary display
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
        | 6️⃣ Flagged tickers
        |--------------------------------------------------------------------------
        */
        $flagged = [];
        foreach ($results as $id => $r) {
            $h = $r['health'] ?? 1;
            if ($h >= 0.9) continue;
            $symbol = DB::table('tickers')->where('id', $id)->value('ticker');
            $type   = DB::table('tickers')->where('id', $id)->value('type');
            $issues = $r['issues'] ?? [];
            $summary = is_array($issues)
                ? implode(', ', array_keys($issues) ?: ['none'])
                : (string) ($issues ?: 'none');
            $flagged[] = [
                'id'       => $id,
                'symbol'   => $symbol,
                'type'     => $type,
                'health'   => $h,
                'severity' => $h < 0.6 ? 'Critical' : 'Moderate',
                'issues'   => $summary,
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
                . str_pad('Symbol', 12)
                . str_pad('Type', 12)
                . str_pad('Health', 10)
                . str_pad('Severity', 12)
                . "\tIssues");
            $this->line(str_repeat('-', 90));

            foreach ($flagged as $f) {
                $sevLabel = $f['severity'] === 'Critical'
                    ? $this->formatRed('Critical')
                    : $this->formatYellow('Moderate');
                $this->line(
                    str_pad($f['id'], 8)
                    . str_pad($f['symbol'], 12)
                    . str_pad($f['type'], 12)
                    . str_pad(number_format($f['health'], 3), 10)
                    . str_pad($sevLabel, 14)
                    . "\t" . $f['issues']
                );
            }
            $this->newLine();
        }

        /*
        |--------------------------------------------------------------------------
        | 7️⃣ Live verification and bulk re-ingest support
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
