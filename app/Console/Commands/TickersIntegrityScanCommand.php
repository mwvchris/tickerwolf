<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\Validation\DataIntegrityService;
use App\Models\DataValidationLog;

/**
 * ============================================================================
 *  tickers:integrity-scan
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Performs a deep scan for data anomalies and structural issues
 *   across ticker-related tables using DataIntegrityService.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Iterates through a configurable number of tickers.
 *   • Delegates per-ticker checks to DataIntegrityService::scanTicker().
 *   • Aggregates all anomalies (nulls, gaps, outliers, duplicates).
 *   • Writes structured summary → `data_validation_logs` table.
 *
 * 💾 Logged Output:
 * ----------------------------------------------------------------------------
 *   entity_type   = 'ticker_integrity'
 *   command_name  = 'tickers:integrity-scan'
 *   details       = JSON summary per ticker
 *   status        = 'success' | 'warning' | 'error'
 *   validation_ratio = validated_count / total_entities (if column exists)
 *
 * 🧩 Related Commands:
 *   • tickers:validate-data → completeness validation
 *   • tickers:integrity-scan → structural/anomaly validation
 *
 * ============================================================================
 */
class TickersIntegrityScanCommand extends Command
{
    protected $signature = 'tickers:integrity-scan 
                            {--limit=100 : Number of tickers to scan}
                            {--from-id=0 : Start scanning from this ticker ID}';

    protected $description = 'Scan ticker data for anomalies, gaps, or invalid structures';

    public function handle(): int
    {
        $limit   = (int) $this->option('limit');
        $fromId  = (int) $this->option('from-id');

        $this->info("🧩 Starting integrity scan for up to {$limit} tickers (from ID {$fromId})…");
        Log::channel('ingest')->info("🧩 tickers:integrity-scan started", [
            'limit'  => $limit,
            'fromId' => $fromId,
        ]);

        $startedAt = now();

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Create persistent log entry
        |--------------------------------------------------------------------------
        */
        $log = DataValidationLog::create([
            'entity_type'   => 'ticker_integrity',
            'command_name'  => 'tickers:integrity-scan',
            'status'        => 'success',
            'started_at'    => $startedAt,
            'initiated_by'  => get_current_user() ?: 'system',
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Initialize scanner
        |--------------------------------------------------------------------------
        */
        $service = new DataIntegrityService();
        $tickers = DB::table('tickers')
            ->where('id', '>=', $fromId)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->toArray();

        $results = [];
        $issuesFound = 0;

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Run per-ticker scans
        |--------------------------------------------------------------------------
        */
        foreach ($tickers as $id) {
            try {
                $result = $service->scanTicker($id);
                $results[$id] = $result;

                if (!empty($result['issues'])) {
                    $issuesFound += count($result['issues']);
                    $this->warn("⚠️ Ticker {$id} anomalies: " . implode(', ', array_keys($result['issues'])));
                }
            } catch (\Throwable $e) {
                $issuesFound++;
                $results[$id] = ['error' => $e->getMessage()];
                Log::channel('ingest')->error("❌ Integrity scan failed for ticker {$id}", [
                    'message' => $e->getMessage(),
                    'trace'   => substr($e->getTraceAsString(), 0, 500),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Aggregate summary
        |--------------------------------------------------------------------------
        */
        $total = count($tickers);

        // Safe, clamped numeric logic to prevent negatives or overflow
        $validated = max(0, min($total, $total - $issuesFound));
        $missing   = max(0, $issuesFound);
        $ratio     = $total > 0 ? round($validated / $total, 6) : 0.0;

        $status = $issuesFound > 0 ? 'warning' : 'success';

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ Persist structured log summary
        |--------------------------------------------------------------------------
        */
        $logPayload = [
            'total_entities'   => $total,
            'validated_count'  => $validated,
            'missing_count'    => $missing,
            'status'           => $status,
            'completed_at'     => now(),
        ];

        // Safely JSON encode details (longtext column)
        try {
            $logPayload['details'] = json_encode($results, JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            $logPayload['details'] = json_encode([
                'error'   => 'JSON encode failed',
                'message' => $e->getMessage(),
            ]);
        }

        // Include optional ratio if schema supports it
        if (Schema::hasColumn('data_validation_logs', 'validation_ratio')) {
            $logPayload['validation_ratio'] = $ratio;
        }

        $log->update($logPayload);

        /*
        |--------------------------------------------------------------------------
        | 6️⃣ Console + Log summary
        |--------------------------------------------------------------------------
        */
        if ($issuesFound > 0) {
            $this->warn("⚠️ Integrity scan complete — {$issuesFound} total anomalies detected ({$validated}/{$total} valid).");
        } else {
            $this->info("✅ Integrity scan complete — all {$total} tickers passed validation.");
        }

        Log::channel('ingest')->info("✅ tickers:integrity-scan complete", [
            'issues_found'      => $issuesFound,
            'scanned'           => $total,
            'validated_count'   => $validated,
            'validation_ratio'  => $ratio,
            'status'            => $status,
        ]);

        return Command::SUCCESS;
    }
}