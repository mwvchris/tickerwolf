<?php

namespace App\Jobs;

use App\Services\Compute\FeaturePipeline;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 *  ComputeTickerIndicatorsJob
 * ============================================================================
 *
 * 🔧 Purpose:
 *   Queued job responsible for computing technical indicators
 *   (MACD, ATR, ADX, VWAP, etc.) for one or more tickers.
 *
 * 🧠 Design Overview:
 * ----------------------------------------------------------------------------
 *   • Delegates all compute logic to `FeaturePipeline`
 *   • Supports batched execution for thousands of tickers
 *   • Handles automatic persistence to:
 *       - `ticker_indicators` (core indicator storage)
 *       - `ticker_feature_snapshots` (optional)
 *   • Fully Horizon/queue-monitoring compatible
 *
 * 💡 Usage Example:
 * ----------------------------------------------------------------------------
 *   // Standard dispatch for explicit tickers + indicators
 *   dispatch(new ComputeTickerIndicatorsJob(
 *       tickerIds: [1, 2, 3],
 *       indicators: ['macd', 'atr', 'adx', 'vwap'],
 *       range: ['from' => '2023-01-01', 'to' => '2023-12-31']
 *   ));
 *
 *   // Minimal dispatch (used by tickers:backfill-indicators)
 *   dispatch(new ComputeTickerIndicatorsJob([1, 2, 3], ['macd','atr']));
 *
 * ============================================================================
 */
class ComputeTickerIndicatorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /** @var array<int> IDs of tickers to process. */
    public array $tickerIds;

    /** @var array<string> Indicator names to compute (e.g. ['macd','atr']). */
    public array $indicators;

    /** @var array{from?:string,to?:string} Optional date range. */
    public array $range;

    /** @var array Arbitrary parameter overrides. */
    public array $params;

    /** @var bool Whether to also generate feature snapshots after DB writes. */
    public bool $writeSnapshots;

    /** @var int Maximum retry attempts before job is marked as failed. */
    public int $tries = 3;

    /** @var int Maximum job runtime (seconds). */
    public int $timeout = 900; // extended slightly for larger batches

    /**
     * -------------------------------------------------------------------------
     *  Constructor
     * -------------------------------------------------------------------------
     *
     * @param  array<int>  $tickerIds
     * @param  array<string>  $indicators
     * @param  array{from?:string,to?:string}  $range
     * @param  array  $params
     * @param  bool  $writeSnapshots
     */
    public function __construct(
        array $tickerIds,
        array $indicators = [],
        array $range = [],
        array $params = [],
        bool $writeSnapshots = true
    ) {
        $this->tickerIds      = $tickerIds;
        $this->indicators     = $indicators ?: ['macd', 'atr', 'adx', 'vwap'];
        $this->range          = $range;
        $this->params         = $params;
        $this->writeSnapshots = $writeSnapshots;
    }

    /**
     * -------------------------------------------------------------------------
     *  Handle
     * -------------------------------------------------------------------------
     * Executes the indicator computation pipeline for each ticker.
     * Writes results to the database and optionally updates snapshots.
     * -------------------------------------------------------------------------
     */
    public function handle(FeaturePipeline $pipeline): void
    {
        // Safety: skip if batch has been cancelled.
        if (method_exists($this, 'batch') && $this->batch()?->cancelled()) {
            Log::channel('ingest')->warning('⏭️ Skipping ComputeTickerIndicatorsJob — batch cancelled.', [
                'tickers' => $this->tickerIds,
            ]);
            return;
        }

        Log::channel('ingest')->info('🚀 Starting ComputeTickerIndicatorsJob', [
            'tickers'   => $this->tickerIds,
            'indicators'=> $this->indicators,
            'range'     => $this->range,
            'snapshots' => $this->writeSnapshots ? 'enabled' : 'disabled',
        ]);

        foreach ($this->tickerIds as $tickerId) {
            try {
                $result = $pipeline->runForTicker(
                    tickerId: $tickerId,
                    indicatorNames: $this->indicators,
                    range: $this->range,
                    params: $this->params,
                    writeCoreToDb: true,
                    buildSnapshots: $this->writeSnapshots,
                    primeCache: false
                );

                Log::channel('ingest')->info('✅ Indicators computed successfully', [
                    'ticker_id'        => $tickerId,
                    'rows_inserted'    => $result['inserted'] ?? null,
                    'snapshots_written'=> $result['snapshots'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::channel('ingest')->error('❌ Indicator computation failed', [
                    'ticker_id' => $tickerId,
                    'message'   => $e->getMessage(),
                    'trace'     => substr($e->getTraceAsString(), 0, 800),
                ]);
                throw $e; // Allow Laravel retry/backoff
            }
        }

        Log::channel('ingest')->info('🏁 ComputeTickerIndicatorsJob complete', [
            'tickers'    => $this->tickerIds,
            'indicators' => $this->indicators,
        ]);
    }

    /**
     * -------------------------------------------------------------------------
     *  Tags for Horizon / Job Monitoring
     * -------------------------------------------------------------------------
     */
    public function tags(): array
    {
        return [
            'compute',
            'tickers:' . implode(',', array_slice($this->tickerIds, 0, 5)) . (count($this->tickerIds) > 5 ? '...' : ''),
            'indicators:' . implode(',', $this->indicators),
            'snapshots:' . ($this->writeSnapshots ? 'on' : 'off'),
        ];
    }
}