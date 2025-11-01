<?php

namespace App\Services\Compute;

use App\Models\TickerFeatureSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Class FeatureSnapshotService
 *
 * Responsible for consolidating per-indicator rows into compact per-day feature vectors.
 *
 * Role in pipeline:
 * - After indicators are computed for a ticker (via FeaturePipeline),
 *   this service aggregates all indicator results for each timestamp
 *   and writes a JSON blob into `ticker_feature_snapshots`.
 *
 * Benefits:
 * - Reduces 20+ indicator rows into one daily record.
 * - Provides AI/ML-ready structure for model ingestion.
 * - Decouples heavy computation from data consumption layers.
 */
class FeatureSnapshotService
{
    /**
     * Build or update feature snapshots for a single ticker.
     *
     * @param int   $tickerId
     * @param array $rows Array of computed indicators (from FeaturePipeline)
     *
     * Expected input format:
     * [
     *   ['t' => '2025-10-30', 'indicator' => 'sma_20', 'value' => 142.5, 'meta' => null],
     *   ['t' => '2025-10-30', 'indicator' => 'ema_12', 'value' => 143.0, 'meta' => null],
     *   ...
     * ]
     *
     * This will consolidate by timestamp:
     * {
     *   "sma_20": 142.5,
     *   "ema_12": 143.0,
     *   ...
     * }
     */
    public function upsertSnapshots(int $tickerId, array $rows): void
    {
        if (empty($rows)) {
            Log::channel('ingest')->info("🟡 No rows passed to FeatureSnapshotService", ['ticker_id' => $tickerId]);
            return;
        }

        // Group indicators by timestamp
        $grouped = [];
        foreach ($rows as $r) {
            $t = substr($r['t'], 0, 10); // daily resolution
            $grouped[$t][$r['indicator']] = $r['value'];
        }

        $now = now()->toDateTimeString();
        $payload = [];

        foreach ($grouped as $date => $indicators) {
            $payload[] = [
                'ticker_id'  => $tickerId,
                't'          => $date,
                'indicators' => json_encode($indicators),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('ticker_feature_snapshots')->upsert(
            $payload,
            ['ticker_id', 't'],
            ['indicators', 'updated_at']
        );

        Log::channel('ingest')->info("📦 Feature snapshots upserted", [
            'ticker_id' => $tickerId,
            'snapshots' => count($payload),
        ]);
    }
}