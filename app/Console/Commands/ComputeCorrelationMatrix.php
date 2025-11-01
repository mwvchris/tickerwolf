<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Analytics\CorrelationMatrixService;

class ComputeCorrelationMatrix extends Command
{
    protected $signature = 'compute:correlation-matrix {--window=20} {--lookback=90}';
    protected $description = 'Compute inter-ticker correlation matrix and persist results.';

    public function handle(CorrelationMatrixService $service): int
    {
        $window = (int)$this->option('window');
        $lookback = (int)$this->option('lookback');

        $this->info("Computing correlation matrix (window={$window}, lookback={$lookback})...");
        $service->computeMatrix($lookback, $window);
        $this->info('✅ Correlation matrix computation complete.');

        return self::SUCCESS;
    }
}
