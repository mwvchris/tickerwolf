<?php

namespace App\Jobs;

use App\Services\Analytics\FeatureSnapshotBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Class BuildTickerSnapshotJob
 *
 * Queued job responsible for building (or previewing) JSON feature snapshots
 * for one or more tickers across a specified date range.
 *
 * Each snapshot represents a merged analytics vector containing:
 *   - Computed indicators from ticker_indicators
 *   - Derived analytics (Sharpe Ratio, Beta, Volatility, etc.)
 *   - Optional placeholders for sentiment/embeddings
 *
 * Designed for parallel execution under Laravel’s Bus::batch()
 * via the TickersBuildSnapshots command.
 */
class BuildTickerSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /** @var array<int> List of ticker IDs to process. */
    public array $tickerIds;

    /** @var array{from?:string,to?:string} Optional date range for computation. */
    public array $range;

    /** @var array Optional runtime parameters (unused for now). */
    public array $params;

    /** @var bool Whether this job runs in dry-run preview mode. */
    public bool $preview;

    /** @var int Maximum retry attempts for transient failures. */
    public int $tries = 3;

    /** @var int Timeout (in seconds) per job execution. */
    public int $timeout = 600;

    /** @var int Backoff delay (seconds) before retrying failed jobs. */
    public int $backoff = 15;

    /**
     * @param array<int> $tickerIds
     * @param array{from?:string,to?:string} $range
     * @param array $params
     * @param bool $preview
     */
    public function __construct(array $tickerIds, array $range = [], array $params = [], bool $preview = false)
    {
        $this->tickerIds = $tickerIds;
        $this->range     = $range;
        $this->params    = $params;
        $this->preview   = $preview;
    }

    /**
     * Execute the job.
     *
     * For each ticker:
     *  - Loads indicator data
     *  - Computes derived metrics
     *  - Builds JSON feature snapshots
     *  - Writes or logs results depending on --preview mode
     */
    public function handle(FeatureSnapshotBuilder $builder): void
    {
        // ---------------------------------------------------------------------
        // 1️⃣ Batch cancellation safety check
        // ---------------------------------------------------------------------
        if (method_exists($this, 'batch') && $this->batch()?->cancelled()) {
            Log::channel('ingest')->warning('⏭️ Skipping BuildTickerSnapshotJob — parent batch cancelled.', [
                'class'   => static::class,
                'tickers' => $this->tickerIds,
            ]);
            return;
        }

        // ---------------------------------------------------------------------
        // 2️⃣ Job start log
        // ---------------------------------------------------------------------
        Log::channel('ingest')->info('🚀 Starting BuildTickerSnapshotJob', [
            'tickers' => $this->tickerIds,
            'range'   => $this->range,
            'preview' => $this->preview,
            'job_id'  => $this->job?->getJobId() ?? null,
        ]);

        if ($this->preview) {
            Log::channel('ingest')->info('💡 Preview mode active — snapshots will be simulated only (no DB writes).', [
                'tickers' => $this->tickerIds,
            ]);
        }

        // ---------------------------------------------------------------------
        // 3️⃣ Sequentially build snapshots for each ticker
        // ---------------------------------------------------------------------
        $totalSnapshots = 0;

        foreach ($this->tickerIds as $index => $tickerId) {
            $pos = $index + 1;
            $total = count($this->tickerIds);

            try {
                Log::channel('ingest')->info("▶️ Building snapshot for ticker {$tickerId} ({$pos}/{$total})", [
                    'range'   => $this->range,
                    'preview' => $this->preview,
                ]);

                $res = $builder->buildForTicker($tickerId, $this->range, $this->params, $this->preview);
                $snapCount = $res['snapshots'] ?? 0;
                $totalSnapshots += $snapCount;

                Log::channel('ingest')->info('✅ Snapshot built successfully', [
                    'ticker_id' => $tickerId,
                    'snapshots' => $snapCount,
                    'range'     => $this->range,
                    'preview'   => $this->preview,
                ]);
            } catch (\Throwable $e) {
                // Handle per-ticker failure safely; let Laravel retry if needed
                Log::channel('ingest')->error('❌ Snapshot build failed', [
                    'ticker_id' => $tickerId,
                    'error'     => $e->getMessage(),
                    'trace'     => substr($e->getTraceAsString(), 0, 900),
                    'range'     => $this->range,
                    'preview'   => $this->preview,
                ]);

                // Allow retry by rethrowing exception
                throw $e;
            }
        }

        // ---------------------------------------------------------------------
        // 4️⃣ Completion log
        // ---------------------------------------------------------------------
        Log::channel('ingest')->info('🏁 BuildTickerSnapshotJob complete', [
            'tickers'        => $this->tickerIds,
            'snapshots_total'=> $totalSnapshots,
            'preview'        => $this->preview,
        ]);
    }

    /**
     * Tags for Laravel Horizon monitoring.
     */
    public function tags(): array
    {
        return [
            'snapshots',
            'tickers:' . implode(',', $this->tickerIds),
            'preview:' . ($this->preview ? 'true' : 'false'),
            'range:' . ($this->range['from'] ?? 'none') . '-' . ($this->range['to'] ?? 'none'),
        ];
    }
}