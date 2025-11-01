<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\DataValidationLog;

/**
 * ============================================================================
 *  tickers:validate-data
 * ============================================================================
 * Performs a full data integrity audit of all ticker-dependent tables
 * and logs the run summary to `data_validation_logs`.
 * ============================================================================
 */
class TickersValidateDataCommand extends Command
{
    protected $signature = 'tickers:validate-data {--summary-only : Show summary counts only}';
    protected $description = 'Validate data completeness and indicator coverage across all tables';

    public function handle(): int
    {
        $startedAt = now();

        $log = DataValidationLog::create([
            'entity_type' => 'ticker',
            'command_name' => 'tickers:validate-data',
            'status' => 'success',
            'started_at' => $startedAt,
            'initiated_by' => get_current_user() ?: 'system',
        ]);

        $this->info("🔍 Running full data validation…");
        Log::channel('ingest')->info("🔍 tickers:validate-data started");

        $totalTickers = DB::table('tickers')->count();
        $summary = [];

        $check = function (string $table, string $col = 'ticker_id') use (&$summary, $totalTickers) {
            $count = DB::table($table)->distinct($col)->count($col);
            $missing = $totalTickers - $count;
            $summary[$table] = ['count' => $count, 'missing' => $missing];
            return $summary[$table];
        };

        $check('ticker_price_histories');
        $check('ticker_fundamentals');
        $check('ticker_overviews');
        $check('ticker_feature_metrics');
        $check('ticker_feature_snapshots');
        $check('ticker_indicators');

        $missingTotal = array_sum(array_column($summary, 'missing'));

        $this->table(
            ['Table', 'Present', 'Total', 'Missing'],
            collect($summary)->map(fn($s, $tbl) => [$tbl, $s['count'], $totalTickers, $s['missing']])->toArray()
        );

        if ($missingTotal > 0) {
            $this->warn("⚠️ Missing data detected in one or more tables.");
            $log->status = 'warning';
        } else {
            $this->info("✅ All tables validated successfully.");
        }

        // Persist JSON summary in the log record
        $log->update([
            'total_entities' => $totalTickers,
            'validated_count' => $totalTickers - $missingTotal,
            'missing_count' => $missingTotal,
            'details' => $summary,
            'completed_at' => now(),
        ]);

        Log::channel('ingest')->info("✅ tickers:validate-data complete", [
            'summary' => $summary,
            'missing_total' => $missingTotal,
        ]);

        return Command::SUCCESS;
    }
}