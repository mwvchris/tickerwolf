<?php

namespace App\Services\Validation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 *  DataIntegrityService
 * ============================================================================
 * Scans ticker_price_histories for data anomalies:
 *  • Duplicate timestamps
 *  • Missing date gaps
 *  • Flatline sequences
 *  • Abnormal return spikes (> ±30%)
 * ============================================================================
 */
class DataIntegrityService
{
    public function scanTicker(int $tickerId): array
    {
        $bars = DB::table('ticker_price_histories')
            ->where('ticker_id', $tickerId)
            ->where('resolution', '1d')
            ->orderBy('t', 'asc')
            ->get(['t', 'c'])
            ->map(fn($r) => ['t' => $r->t, 'c' => (float)$r->c])
            ->toArray();

        if (count($bars) < 5) {
            return ['ticker_id' => $tickerId, 'status' => 'insufficient'];
        }

        $issues = [];

        // Check duplicate timestamps
        $dupes = collect($bars)->groupBy('t')->filter(fn($g) => $g->count() > 1)->keys()->all();
        if ($dupes) $issues['duplicates'] = $dupes;

        // Check for gaps (missing weekdays)
        $dates = array_column($bars, 't');
        for ($i = 1; $i < count($dates); $i++) {
            $diff = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
            if ($diff > 3) $issues['gaps'][] = ['from' => $dates[$i - 1], 'to' => $dates[$i]];
        }

        // Check flatlines and spikes
        for ($i = 1; $i < count($bars); $i++) {
            $prev = $bars[$i - 1]['c'];
            $curr = $bars[$i]['c'];
            if ($prev == $curr) $issues['flat'][] = $dates[$i];
            $ret = ($curr - $prev) / max($prev, 1e-8);
            if (abs($ret) > 0.30) $issues['spikes'][] = ['date' => $dates[$i], 'return' => round($ret, 2)];
        }

        if ($issues) {
            Log::channel('ingest')->warning("⚠️ Data anomaly detected", [
                'ticker_id' => $tickerId,
                'issues' => array_keys($issues),
            ]);
        }

        return ['ticker_id' => $tickerId, 'issues' => $issues];
    }
}