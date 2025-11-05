<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * =============================================================================
 *  tickers:integrity-scan  (Iteration 2 — Hybrid Verification + Lifecycle)
 * =============================================================================
 *
 * PURPOSE
 * -------
 * Extends the DB-only scan with a lightweight live verification phase
 * (limit=5 probe) for incomplete tickers.  It also infers lifecycle state:
 *   IPO_recent / Active_incomplete / Defunct_delisted / Empty
 *
 * Key new flags:
 *   --verify-live    -> enables Polygon API probes for non-full tickers.
 *   --apply           -> commits lifecycle/deactivation updates to DB.
 *
 * All API hits use limit=5 and a 1-day range — purely to confirm existence.
 * It’s effectively a HEAD check, not a full price fetch.
 *
 * Safe defaults:
 *   • FULL tickers are never probed.
 *   • PARTIAL/INSUFFICIENT tickers use cached 1d requests.
 *   • Dry-run by default (no DB writes unless --apply given).
 *
 * =============================================================================
 */
class TickersIntegrityScanCommand extends Command
{
    protected $signature = 'tickers:integrity-scan
        {--limit=0 : Number of tickers to scan (0 = all from from-id)}
        {--from-id=0 : Start scanning from this ticker ID}
        {--baseline=auto : Baseline strategy: auto|max|mode|<integer>}
        {--verify-live : Verify incomplete tickers against Polygon API}
        {--apply : Apply lifecycle/deactivation flags in DB (dry-run by default)}
        {--export= : Optional CSV export path under storage/}
        {--progress-chunk=500 : Advance progress bar every N tickers}';

    protected $description = 'Hybrid integrity scan: DB classification + optional Polygon existence check.';

    // Tunables
    protected int $chunkSizeIds      = 2000;
    protected int $minBarsThreshold  = 10;
    protected float $fullCutoff      = 0.99;
    protected float $partialCutoff   = 0.50;

    public function handle(): int
    {
        $start = microtime(true);
        $this->minBarsThreshold = (int) config('data_validation.min_bars', $this->minBarsThreshold);

        // Parse options
        $limit       = (int) $this->option('limit');
        $fromId      = (int) $this->option('from-id');
        $baselineOpt = trim((string) $this->option('baseline'));
        $verifyLive  = (bool) $this->option('verify-live');
        $apply       = (bool) $this->option('apply');
        $exportPath  = $this->option('export');
        $progressChunk = max(1, (int) $this->option('progress-chunk'));

        $this->line('');
        $this->info("🧩 Ticker Integrity Scan (Iteration 2)");
        $this->line("   • from-id  : {$fromId}");
        $this->line("   • limit    : " . ($limit ?: 'ALL'));
        $this->line("   • baseline : {$baselineOpt}");
        $this->line("   • verify   : " . ($verifyLive ? '✅ yes' : 'no'));
        $this->line("   • apply    : " . ($apply ? '⚠️ yes (will modify DB)' : 'dry-run'));
        $this->line('');

        Log::channel('ingest')->info('🧩 Iteration2 integrity scan start', [
            'from_id' => $fromId, 'limit' => $limit, 'baseline' => $baselineOpt,
            'verify_live' => $verifyLive, 'apply' => $apply
        ]);

        // ---------------------------------------------------------------------
        // Step 1: Pull tickers
        // ---------------------------------------------------------------------
        $tickers = DB::table('tickers')
            ->where('id', '>=', $fromId)
            ->orderBy('id')
            ->when($limit > 0, fn($q) => $q->limit($limit))
            ->select(['id', 'ticker', 'type', 'is_active_polygon', 'deactivation_reason'])
            ->get();

        $total = $tickers->count();
        if ($total === 0) {
            $this->warn('No tickers found.');
            return Command::SUCCESS;
        }

        $tickerMap = $tickers->mapWithKeys(fn($t) => [
            (int) $t->id => [
                'symbol' => $t->ticker,
                'type'   => $t->type ?? 'CS',
                'is_active_polygon' => (int) $t->is_active_polygon,
                'deactivation_reason' => $t->deactivation_reason,
            ]
        ])->all();

        $ids = array_keys($tickerMap);

        // ---------------------------------------------------------------------
        // Step 2: Aggregate bar counts
        // ---------------------------------------------------------------------
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
                $tid = (int) $r->ticker_id;
                $bars = (int) $r->bars;
                $agg[$tid] = [
                    'bars' => $bars,
                    'first_t' => $r->first_t,
                    'last_t'  => $r->last_t
                ];
                $counts[] = $bars;
            }
            $progress->advance(count($chunk));
        }

        // Fill empties
        foreach ($ids as $tid) {
            if (!isset($agg[$tid])) {
                $agg[$tid] = ['bars' => 0, 'first_t' => null, 'last_t' => null];
                $counts[] = 0;
            }
        }

        $progress->finish();
        $this->newLine(2);

        // ---------------------------------------------------------------------
        // Step 3: Baseline derivation
        // ---------------------------------------------------------------------
        [$maxBars, $modeBars, $modeCount] = $this->maxAndMode($counts);
        $baseline = $this->computeBaseline($counts, $baselineOpt);
        $this->info("📏 Baseline: {$baseline} (max={$maxBars}, mode={$modeBars}×{$modeCount})");
        $this->line('');

        // ---------------------------------------------------------------------
        // Step 4: Classification (same logic as Iteration 1)
        // ---------------------------------------------------------------------
        $buckets = ['FULL'=>[], 'PARTIAL'=>[], 'INSUFFICIENT'=>[], 'EMPTY'=>[]];

        foreach ($ids as $tid) {
            $bars = $agg[$tid]['bars'];
            $coverage = $baseline > 0 ? $bars / $baseline : 0;
            if ($bars === 0) {
                $bucket = 'EMPTY';
            } elseif ($bars < $this->minBarsThreshold) {
                $bucket = 'INSUFFICIENT';
            } elseif ($coverage >= $this->fullCutoff) {
                $bucket = 'FULL';
            } else {
                $bucket = 'PARTIAL';
            }
            $buckets[$bucket][] = $tid;
        }

        // ---------------------------------------------------------------------
        // Step 5: Live verification (optional)
        // ---------------------------------------------------------------------
        $apiKey = config('services.polygon.key');
        $client = Http::timeout(10)->acceptJson();
        $verified = [];

        if ($verifyLive) {
            $toVerify = array_merge($buckets['PARTIAL'], $buckets['INSUFFICIENT']);
            $totalLive = count($toVerify);
            $this->line("🌐 Live verification for {$totalLive} incomplete tickers...");
            $bar = $this->output->createProgressBar($totalLive);
            $bar->setFormat(' [%bar%] %percent:3s%% | %current%/%max% ');
            $bar->start();

            foreach ($toVerify as $tid) {
                $symbol = $tickerMap[$tid]['symbol'];
                $result = $this->probePolygon($client, $apiKey, $symbol);
                $verified[$tid] = $result;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        }

        // ---------------------------------------------------------------------
        // Step 6: Lifecycle inference + recommended actions
        // ---------------------------------------------------------------------
        $updates = [];
        $rowsForCsv = [];

        foreach ($ids as $tid) {
            $symbol = $tickerMap[$tid]['symbol'];
            $type   = $tickerMap[$tid]['type'];
            $bars   = $agg[$tid]['bars'];
            $first  = $agg[$tid]['first_t'];
            $last   = $agg[$tid]['last_t'];
            $coverage = $baseline > 0 ? round(($bars / $baseline) * 100, 2) : 0.0;

            $life = $this->inferLifecycle($first, $last, $verified[$tid]['status'] ?? null, $verified[$tid]['resultsCount'] ?? null);

            $recommend = match ($life) {
                'IPO_recent'         => 'keep_active',
                'Active_incomplete'  => 'keep_active',
                'Defunct_delisted'   => 'deactivate',
                'Empty'              => 'deactivate',
                default              => 'review',
            };

            if ($apply && in_array($recommend, ['deactivate'])) {
                DB::table('tickers')
                    ->where('id', $tid)
                    ->update([
                        'is_active_polygon' => 0,
                        'deactivation_reason' => $life,
                    ]);
                $updates[] = $tid;
            }

            $rowsForCsv[] = [
                'id' => $tid,
                'symbol' => $symbol,
                'type' => $type,
                'bars' => $bars,
                'coverage' => $coverage,
                'first' => $first,
                'last' => $last,
                'lifecycle' => $life,
                'recommend' => $recommend,
                'upstream_status' => $verified[$tid]['polygon_status'] ?? null,
                'upstream_resultsCount' => $verified[$tid]['resultsCount'] ?? null,
            ];
        }

        // ---------------------------------------------------------------------
        // Step 7: Summary
        // ---------------------------------------------------------------------
        $lifeGroups = collect($rowsForCsv)->groupBy('lifecycle')->map->count()->all();
        $this->info('📊 Lifecycle Summary');
        foreach ($lifeGroups as $label => $count) {
            $this->line("   • {$label} : {$count}");
        }
        $this->line('');
        if ($apply) $this->warn('⚙️ DB updated for ' . count($updates) . ' tickers');

        // ---------------------------------------------------------------------
        // Step 8: Optional export
        // ---------------------------------------------------------------------
        if ($exportPath) {
            $this->exportCsv($exportPath, $rowsForCsv, [
                'id','symbol','type','bars','coverage','first','last',
                'lifecycle','recommend','upstream_status','upstream_resultsCount'
            ]);
            $this->info("📄 CSV exported to storage/{$exportPath}");
        }

        Log::channel('ingest')->info('✅ Iteration2 integrity scan complete', [
            'baseline' => $baseline, 'verify_live' => $verifyLive,
            'apply' => $apply, 'elapsed' => round(microtime(true) - $start, 3)
        ]);

        $this->line('');
        $this->info('✅ Done');
        return Command::SUCCESS;
    }

    // =========================================================================
    //  Helper: lightweight Polygon existence probe
    // =========================================================================
    protected function probePolygon($client, string $apiKey, string $symbol): array
    {
        $url = "https://api.polygon.io/v2/aggs/ticker/{$symbol}/range/1/day/2024-01-01/2024-01-02";
        try {
            $resp = $client->get($url, ['limit' => 5, 'adjusted' => 'true', 'apiKey' => $apiKey]);
            $json = $resp->json();
            return [
                'status' => $resp->status(),
                'polygon_status' => $json['status'] ?? 'UNKNOWN',
                'resultsCount' => $json['resultsCount'] ?? 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('Polygon probe failed', ['symbol' => $symbol, 'err' => $e->getMessage()]);
            return ['status' => 0, 'polygon_status' => 'ERROR', 'resultsCount' => 0];
        }
    }

    // =========================================================================
    //  Helper: lifecycle inference
    // =========================================================================
    protected function inferLifecycle(?string $first_t, ?string $last_t, ?string $polygonStatus, ?int $upstreamCount): string
    {
        if (!$first_t && !$last_t) return 'Empty';

        $first = $first_t ? Carbon::parse($first_t) : null;
        $last  = $last_t  ? Carbon::parse($last_t)  : null;
        $now   = Carbon::now();

        $ageDays = $first ? $first->diffInDays($now) : 9999;
        $sinceLast = $last ? $last->diffInDays($now) : 9999;

        if ($ageDays < 365) return 'IPO_recent';
        if ($sinceLast > 90 && ($upstreamCount ?? 0) === 0) return 'Defunct_delisted';
        if ($polygonStatus === 'NOT_FOUND') return 'Defunct_delisted';

        return 'Active_incomplete';
    }

    // =========================================================================
    //  Baseline utilities (same as Iteration 1)
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

    protected function exportCsv(string $path, array $rows, array $headers): void
    {
        $full = storage_path("app/{$path}");
        @mkdir(dirname($full), 0775, true);
        $fp = fopen($full, 'w');
        fputcsv($fp, $headers);
        foreach ($rows as $r) {
            $line = [];
            foreach ($headers as $h) $line[] = $r[$h] ?? '';
            fputcsv($fp, $line);
        }
        fclose($fp);
    }
}