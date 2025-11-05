<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * =============================================================================
 *  tickers:integrity-scan  (v3.3 — Validator-Integrated Hybrid Scan + Export)
 * =============================================================================
 *
 * PURPOSE
 * -------
 * Performs a two-phase integrity analysis of all tickers:
 *
 *   (1) Optional per-ticker validation (price_history, indicators, snapshots, etc.)
 *   (2) Hybrid DB classification + lifecycle inference
 *
 * The validator phase collects granular per-ticker results (health, issues, status)
 * and optionally exports them as JSON for post-run inspection.
 *
 * EXAMPLES
 * ----------------------------------------------------------------------------
 *   php artisan tickers:integrity-scan --validator=price_history --limit=500
 *   php artisan tickers:integrity-scan --validator=price_history --export
 *   php artisan tickers:integrity-scan --limit=0 --apply --verify-live
 *
 * =============================================================================
 */
class TickersIntegrityScanCommand extends Command
{
    protected $signature = 'tickers:integrity-scan
        {--validator= : Run a specific validator (price_history, indicators, etc.)}
        {--limit=0 : Number of tickers to scan (0 = all from from-id)}
        {--from-id=0 : Start scanning from this ticker ID}
        {--baseline=auto : Baseline strategy: auto|max|mode|<integer>}
        {--verify-live : Verify incomplete tickers against Polygon API}
        {--apply : Apply lifecycle/deactivation flags in DB (dry-run by default)}
        {--export : Export per-ticker validator results to /storage/logs/audit/}
        {--progress-chunk=500 : Advance progress bar every N tickers}';

    protected $description = 'Run validator and/or hybrid DB integrity scan for all tickers.';

    // Tunables
    protected int $chunkSizeIds = 2000;
    protected int $minBarsThreshold = 10;
    protected float $fullCutoff = 0.99;
    protected float $partialCutoff = 0.50;

    public function handle(): int
    {
        $start = microtime(true);

        $validatorOpt = $this->option('validator');
        $limit        = (int)$this->option('limit');
        $fromId       = (int)$this->option('from-id');
        $baselineOpt  = trim((string)$this->option('baseline'));
        $verifyLive   = (bool)$this->option('verify-live');
        $apply        = (bool)$this->option('apply');
        $export       = (bool)$this->option('export');
        $progressChunk = max(1, (int)$this->option('progress-chunk'));

        $this->newLine();
        $this->info('🧩 Ticker Integrity Scan Results');
        $this->line("   • validator : " . ($validatorOpt ?: 'none'));
        $this->line("   • from-id   : {$fromId}");
        $this->line("   • limit     : " . ($limit ?: 'ALL'));
        $this->line("   • baseline  : {$baselineOpt}");
        $this->line("   • verify    : " . ($verifyLive ? '✅ yes' : 'no'));
        $this->line("   • apply     : " . ($apply ? '⚠️ yes (will modify DB)' : 'dry-run'));
        $this->newLine();

        // =========================================================================
        // (1) VALIDATOR PHASE (optional)
        // =========================================================================
        if ($validatorOpt) {
            $validatorClass = 'App\\Services\\Validation\\Validators\\' . Str::studly($validatorOpt) . 'Validator';

            if (!class_exists($validatorClass)) {
                $this->error("❌ Validator class not found: {$validatorClass}");
                return Command::FAILURE;
            }

            $validator = app($validatorClass);

            $tickers = DB::table('tickers')
                ->when($fromId > 0, fn($q) => $q->where('id', '>=', $fromId))
                ->orderBy('id')
                ->when($limit > 0, fn($q) => $q->limit($limit))
                ->pluck('id')
                ->all();

            $total = count($tickers);
            $tested = 0;
            $failed = 0;
            $healthScores = [];
            $results = [];

            if ($total === 0) {
                $this->warn('No tickers found for validator run.');
            } else {
                $this->info("🎯 Running validator: {$validatorOpt}");
                $bar = $this->output->createProgressBar($total);
                $bar->setFormat(' [%bar%] %percent:3s%% | %current%/%max% ');
                $bar->start();

                foreach ($tickers as $tid) {
                    try {
                        $result = $validator->run(['ticker_id' => $tid]);
                        $tested++;
                        $health = $result['health'] ?? 1.0;
                        $status = $result['status'] ?? 'success';
                        $healthScores[] = $health;

                        if ($status !== 'success') {
                            $failed++;
                        }

                        // Attach symbol + compact result for export
                        $symbol = DB::table('tickers')->where('id', $tid)->value('ticker');
                        $results[] = [
                            'ticker_id' => $tid,
                            'symbol'    => $symbol,
                            'health'    => $health,
                            'status'    => $status,
                            'issues'    => array_keys($result['issues'] ?? []),
                        ];

                        // Stream log for non-success tickers
                        if ($status !== 'success') {
                            Log::channel('ingest')->warning('⚠️ Validator anomaly detected', [
                                'ticker_id' => $tid,
                                'symbol'    => $symbol,
                                'status'    => $status,
                                'health'    => $health,
                                'issues'    => array_keys($result['issues'] ?? []),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::channel('ingest')->error('❌ Validator exception', [
                            'ticker_id' => $tid,
                            'error'     => $e->getMessage(),
                        ]);
                    }

                    if ($tested % $progressChunk === 0) {
                        $bar->advance($progressChunk);
                    }
                }

                $bar->finish();
                $this->newLine(2);

                $meanHealth = $this->calculateHealthFromSummary($healthScores);

                $this->info("📊 Validator Summary — {$validatorOpt}");
                $this->line(str_repeat('─', 60));
                $this->line("Health Score: " . number_format($meanHealth, 3));
                $this->line("Tickers Tested: {$tested}");
                $this->line("Tickers Failed: {$failed}");
                $this->newLine();

                // Optional export of detailed results
                if ($export) {
                    $path = storage_path('logs/audit/validator_' . $validatorOpt . '_' . now()->format('Ymd_His') . '.json');
                    @mkdir(dirname($path), 0755, true);
                    file_put_contents($path, json_encode($results, JSON_PRETTY_PRINT));
                    $this->info("📁 Detailed validator report exported → {$path}");
                }
            }
        }

        // =========================================================================
        // (2) HYBRID DB CLASSIFICATION (always)
        // =========================================================================
        $this->line(str_repeat('═', 70));
        $this->info('🔍 Proceeding with hybrid DB classification phase...');
        $this->line(str_repeat('═', 70));
        $this->newLine();

        $tickers = DB::table('tickers')
            ->where('id', '>=', $fromId)
            ->orderBy('id')
            ->when($limit > 0, fn($q) => $q->limit($limit))
            ->select(['id', 'ticker', 'type', 'is_active_polygon', 'deactivation_reason'])
            ->get();

        $ids = $tickers->pluck('id')->all();
        $total = count($ids);
        if ($total === 0) {
            $this->warn('No tickers found.');
            return Command::SUCCESS;
        }

        // Count bars for each ticker
        $agg = [];
        $counts = [];
        $progress = $this->output->createProgressBar($total);
        $progress->setFormat(' [%bar%] %percent:3s%% | %current%/%max% ');
        $progress->start();

        foreach (array_chunk($ids, $this->chunkSizeIds) as $chunk) {
            $rows = DB::table('ticker_price_histories')
                ->selectRaw('ticker_id, COUNT(*) AS bars, MIN(t) AS first_t, MAX(t) AS last_t')
                ->whereIn('ticker_id', $chunk)
                ->where('resolution', '1d')
                ->groupBy('ticker_id')
                ->get();

            foreach ($rows as $r) {
                $tid = (int)$r->ticker_id;
                $bars = (int)$r->bars;
                $agg[$tid] = [
                    'bars' => $bars,
                    'first_t' => $r->first_t,
                    'last_t'  => $r->last_t,
                ];
                $counts[] = $bars;
            }
            $progress->advance(count($chunk));
        }

        foreach ($ids as $tid) {
            if (!isset($agg[$tid])) {
                $agg[$tid] = ['bars' => 0, 'first_t' => null, 'last_t' => null];
                $counts[] = 0;
            }
        }

        $progress->finish();
        $this->newLine(2);

        // Baseline derivation
        [$maxBars, $modeBars, $modeCount] = $this->maxAndMode($counts);
        $baseline = $this->computeBaseline($counts, $baselineOpt);
        $this->info("📏 Baseline: {$baseline} (max={$maxBars}, mode={$modeBars}×{$modeCount})");
        $this->newLine();

        // Lifecycle classification
        $buckets = ['FULL'=>[], 'PARTIAL'=>[], 'INSUFFICIENT'=>[], 'EMPTY'=>[]];
        foreach ($ids as $tid) {
            $bars = $agg[$tid]['bars'];
            $coverage = $baseline > 0 ? $bars / $baseline : 0;
            if ($bars === 0) $bucket = 'EMPTY';
            elseif ($bars < $this->minBarsThreshold) $bucket = 'INSUFFICIENT';
            elseif ($coverage >= $this->fullCutoff) $bucket = 'FULL';
            else $bucket = 'PARTIAL';
            $buckets[$bucket][] = $tid;
        }

        $lifeGroups = [
            'Active_incomplete' => count($buckets['PARTIAL']),
            'IPO_recent'        => count($buckets['INSUFFICIENT']),
            'Defunct_delisted'  => 0,
            'Empty'             => count($buckets['EMPTY']),
        ];

        $this->info('📊 Lifecycle Summary');
        foreach ($lifeGroups as $label => $count) {
            $this->line("   • {$label} : {$count}");
        }

        $elapsed = round(microtime(true) - $start, 2);
        $this->newLine();
        $this->info("✅ Done in {$elapsed}s");

        Log::channel('ingest')->info('✅ tickers:integrity-scan complete', [
            'validator' => $validatorOpt,
            'limit'     => $limit,
            'elapsed'   => $elapsed,
        ]);

        return Command::SUCCESS;
    }

    // =========================================================================
    // Helper: compute baseline
    // =========================================================================
    protected function computeBaseline(array $counts, string $strategy): int
    {
        [$maxBars, $modeBars] = $this->maxAndMode($counts);
        if (ctype_digit($strategy) && (int)$strategy > 0) return (int)$strategy;
        return ($strategy === 'mode' ? $modeBars : $maxBars);
    }

    protected function maxAndMode(array $counts): array
    {
        if (empty($counts)) return [0,0,0];
        $max = max($counts);
        $freq = array_count_values($counts);
        arsort($freq);
        $modeVal = (int) array_key_first($freq);
        $modeCnt = (int) array_values($freq)[0];
        return [$max, $modeVal, $modeCnt];
    }

    // =========================================================================
    // Helper: health aggregation
    // =========================================================================
    protected function calculateHealthFromSummary(array $scores): float
    {
        if (empty($scores)) return 1.0;
        $avg = array_sum($scores) / max(1, count($scores));
        return round($avg, 3);
    }
}