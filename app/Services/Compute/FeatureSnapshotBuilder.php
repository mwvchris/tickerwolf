<?php

namespace App\Services\Analytics;

use App\Services\Compute\FeaturePipeline;
use Illuminate\Support\Facades\Log;

/**
 * Class FeatureSnapshotBuilder
 *
 * Generates JSON-encoded “feature vectors” for a given ticker using the
 * configured snapshot-layer indicators. Each vector represents a daily
 * aggregation of multiple indicator values (per config/indicators.php).
 *
 * Responsibilities:
 * - Delegates actual math to FeaturePipeline.
 * - Persists results to ticker_feature_snapshots.
 * - Called by BuildTickerSnapshotJob or artisan command.
 *
 * Example usage:
 *   $builder = app(FeatureSnapshotBuilder::class);
 *   $builder->buildForTicker($id, ['from'=>'2020-01-01']);
 */
class FeatureSnapshotBuilder
{
    public function __construct(private FeaturePipeline $pipeline)
    {
    }

    /**
     * Execute snapshot computation for a single ticker.
     *
     * @param int  $tickerId   The ticker’s numeric ID.
     * @param array{from?:string,to?:string} $range  Optional date filters.
     * @param array $params  Optional indicator-specific overrides.
     * @return array{snapshots:int} Number of snapshot records inserted/updated.
     */
    public function buildForTicker(int $tickerId, array $range = [], array $params = []): array
    {
        $snapshotSet = config('indicators.storage.ticker_feature_snapshots', []);

        if (empty($snapshotSet)) {
            Log::channel('ingest')->warning('⚠️ No snapshot indicators configured in config/indicators.php.');
            return ['snapshots' => 0];
        }

        $result = $this->pipeline->runForTicker(
            tickerId: $tickerId,
            indicatorNames: $snapshotSet,
            range: $range,
            params: $params,
            writeCoreToDb: false,
            buildSnapshots: true,
            primeCache: false
        );

        Log::channel('ingest')->info('🧱 Feature snapshot built', [
            'ticker_id' => $tickerId,
            'snapshot_count' => $result['snapshots'],
        ]);

        return ['snapshots' => $result['snapshots']];
    }
}