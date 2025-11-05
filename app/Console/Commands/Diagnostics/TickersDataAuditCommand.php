<?php

namespace App\Console\Commands\Diagnostics;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\Validation\DataAuditService;

/**
 * ============================================================================
 *  TickersDataAuditCommand
 * ============================================================================
 *
 * 🔍 Purpose:
 *   Cross-table consistency and coverage audit for all ticker-related datasets,
 *   now with per-table and overall system "Health %" scoring.
 *
 * 💡 Example Usage:
 * ----------------------------------------------------------------------------
 *   php artisan tickers:data-audit
 *   php artisan tickers:data-audit --limit=100 --detail
 *   php artisan tickers:data-audit --export
 *
 * 📦 Output:
 *   Results logged to storage/logs/audit/tickers_data_audit.log
 * ============================================================================
 */
class TickersDataAuditCommand extends Command
{
    protected $signature = 'tickers:data-audit
        {--limit=0 : Limit ticker sample size (0 = all)}
        {--export : Export results to storage/logs/audit/ as JSON}
        {--detail : Show extended per-table diagnostic breakdown}';

    protected $description = 'Perform cross-table data consistency audit across all ticker-related tables.';

    public function handle(): int
    {
        $limit   = (int) $this->option('limit');
        $export  = (bool) $this->option('export');
        $detail  = (bool) $this->option('detail');

        $this->info('🧩 Running Ticker Data Audit...');
        $this->line(str_repeat('─', 70));

        $start = microtime(true);
        $audit = app(DataAuditService::class)->run($limit, $detail);
        $elapsed = round(microtime(true) - $start, 2);

        // ---------------------------------------------------------------------
        // 1️⃣ Summary Output
        // ---------------------------------------------------------------------
        $this->newLine();
        $this->info('📊 Table Summary (with Health %)');
        $this->line(str_repeat('─', 70));

        foreach ($audit['tables'] as $table => $meta) {
            $count    = number_format($meta['count']);
            $status   = $meta['status'];
            $health   = str_pad(number_format($meta['health_percent'], 2) . '%', 8);
            $color    = $status === 'OK' ? 'info' : ($status === 'WARN' ? 'comment' : 'error');

            $this->{$color}(sprintf(
                "%-30s %12s  %-8s  %s",
                $table,
                $count,
                $health,
                $status
            ));
        }

        // ---------------------------------------------------------------------
        // 2️⃣ Overall Health Summary
        // ---------------------------------------------------------------------
        $this->newLine();
        $this->info('🧮 Overall System Health');
        $this->line(str_repeat('─', 70));

        $overall = $audit['overall']['system_health_percent'] ?? 0;
        $grade   = $audit['overall']['grade'] ?? 'Unknown';

        $color = match ($grade) {
            'Excellent' => 'info',
            'Good'      => 'comment',
            default     => 'error',
        };

        $this->{$color}(sprintf(
            "Health: %-8s   Grade: %s",
            number_format($overall, 2) . '%',
            $grade
        ));

        // ---------------------------------------------------------------------
        // 3️⃣ Cross-Checks
        // ---------------------------------------------------------------------
        $this->newLine();
        $this->info('⚙️  Cross-Checks');
        $this->line(str_repeat('─', 70));

        foreach ($audit['cross'] as $label => $value) {
            $color = $value > 0 ? 'comment' : 'info';
            $this->{$color}(sprintf("%-40s %d", $label . ':', $value));
        }

        // ---------------------------------------------------------------------
        // 4️⃣ Completion Summary
        // ---------------------------------------------------------------------
        $this->newLine();
        $this->info("✅ Audit complete in {$elapsed}s");
        $this->line("Results logged to: storage/logs/audit/tickers_data_audit.log");

        // ---------------------------------------------------------------------
        // 5️⃣ Export (optional)
        // ---------------------------------------------------------------------
        if ($export) {
            $path = storage_path('logs/audit/tickers_data_audit_' . now()->format('Ymd_His') . '.json');
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, json_encode($audit, JSON_PRETTY_PRINT));
            $this->info("📁 Exported JSON report → {$path}");
        }

        Log::channel('ingest')->info('✅ tickers:data-audit complete', $audit);
        return self::SUCCESS;
    }
}