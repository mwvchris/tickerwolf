<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Analytics\CorrelationMatrixService;

/**
 * ============================================================================
 *  compute:correlation-matrix  (v3 — Configurable via config/correlation.php)
 * ============================================================================
 *
 * This command computes and persists pairwise correlation/beta/R² values
 * between tickers, using parameters from config/correlation.php unless
 * explicitly overridden via CLI options.
 *
 * Example:
 *   php artisan compute:correlation-matrix --window=60 --lookback=180
 *   php artisan compute:correlation-matrix --limit=500 --chunk=100
 *
 * ============================================================================
 */
class ComputeCorrelationMatrix extends Command
{
    protected $signature = 'compute:correlation-matrix
        {--window= : Rolling window (returns) for last-window stats}
        {--lookback= : Calendar days of price history to pull}
        {--chunk= : Ticker block size (chunk × chunk)}
        {--limit= : Optional cap on number of tickers (0 = all)}
        {--min-overlap= : Minimum overlapping return observations per pair}';

    protected $description = 'Compute inter-ticker correlation/beta/R² and persist to ticker_correlations.';

    public function handle(CorrelationMatrixService $service): int
    {
        $config = config('correlation.defaults');

        // Merge options with config defaults
        $window     = (int)($this->option('window') ?: $config['window']);
        $lookback   = (int)($this->option('lookback') ?: $config['lookback_days']);
        $chunk      = (int)($this->option('chunk') ?: $config['chunk_size']);
        $limit      = (int)($this->option('limit') ?: $config['limit']);
        $minOverlap = (int)($this->option('min-overlap') ?: $config['min_overlap']);

        $this->info("Computing correlation matrix (v3)");
        $this->line("  • window      : {$window}");
        $this->line("  • lookback    : {$lookback} days");
        $this->line("  • chunk       : {$chunk}");
        $this->line("  • limit       : " . ($limit > 0 ? $limit : 'ALL'));
        $this->line("  • min-overlap : {$minOverlap}");
        $this->newLine();

        $service->computeMatrix(
            lookbackDays: $lookback,
            window: $window,
            chunkSize: $chunk,
            limit: $limit > 0 ? $limit : null,
            minOverlap: $minOverlap
        );

        $this->info('✅ Correlation matrix computation complete.');
        return self::SUCCESS;
    }
}