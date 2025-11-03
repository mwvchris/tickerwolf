<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 *  tickers:review-missing-data
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Provides a quick overview of tickers that were deactivated or flagged
 *   due to missing Polygon data or other data-source issues.
 *
 * 🧠 Behavior:
 * ----------------------------------------------------------------------------
 *   • Lists all tickers where `is_active_polygon = false`
 *   • Displays their deactivation reason and metadata
 *   • Can optionally re-probe Polygon for verification (coming soon)
 *
 * 💾 Output Columns:
 * ----------------------------------------------------------------------------
 *   | ID | Ticker | Active | Reason | Last Updated |
 *
 * ============================================================================
 */
class TickersReviewMissingDataCommand extends Command
{
    protected $signature = 'tickers:review-missing-data
                            {--limit=100 : Number of inactive tickers to display}
                            {--reason= : Filter by deactivation reason (e.g. no_data_from_polygon)}';

    protected $description = 'List all tickers missing Polygon data or marked inactive for validation review.';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $reason = $this->option('reason');

        $query = DB::table('tickers')
            ->select('id', 'ticker', 'name', 'is_active_polygon', 'deactivation_reason', 'updated_at')
            ->where('is_active_polygon', '=', false)
            ->orderBy('updated_at', 'desc')
            ->limit($limit);

        if ($reason) {
            $query->where('deactivation_reason', $reason);
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            $this->info("✅ No tickers currently marked as inactive (is_active_polygon = false).");
            return Command::SUCCESS;
        }

        $this->info("📉 Found {$records->count()} inactive tickers:");
        $this->newLine();

        $headers = ['ID', 'Ticker', 'Name', 'Active', 'Reason', 'Updated'];
        $rows = [];

        foreach ($records as $r) {
            $rows[] = [
                $r->id,
                $r->ticker,
                str($r->name)->limit(24),
                $r->is_active_polygon ? '✅' : '❌',
                $r->deactivation_reason ?: '—',
                $r->updated_at,
            ];
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->comment("💡 Tip: You can filter by reason — e.g. `php artisan tickers:review-missing-data --reason=no_data_from_polygon`");

        return Command::SUCCESS;
    }
}