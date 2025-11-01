<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * ============================================================================
 *  MomentumIndicator
 * ============================================================================
 *
 * Computes the **momentum** of price over one or more configurable lookback windows.
 *
 * Formula (absolute mode):
 *   Momentum = Close(t) - Close(t - N)
 *
 * Formula (percentage mode, if `percent` param = true):
 *   Momentum = ((Close(t) / Close(t - N)) - 1) * 100
 *
 * Interpretation:
 * - Positive → upward acceleration (bullish)
 * - Negative → downward acceleration (bearish)
 * - Near zero → neutral or consolidating trend
 *
 * Features:
 * - Supports multiple window configurations (e.g., 10, 20, 50).
 * - Compatible with hybrid data storage (ticker_indicators + snapshots).
 * - Returns per-window indicator records using standard key format: momentum_{N}.
 *
 * ============================================================================
 */
class MomentumIndicator extends BaseIndicator
{
    /** @var string Unique short name */
    public string $name = 'momentum';

    /** @var string Human-readable display name */
    public string $displayName = 'Momentum';

    /** @var bool Whether this indicator produces multiple keyed outputs */
    public bool $multiSeries = false;

    /** @var array Default parameters */
    public array $defaults = [
        'window'  => 10,        // primary lookback period
        'period'  => 10,        // alias for backward compatibility
        'windows' => [10],      // allow multiple simultaneous computations
        'percent' => false,     // true = percentage change mode
    ];

    /**
     * Compute one or more momentum series from OHLCV bar data.
     *
     * @param  array $bars   Normalized OHLCV data (ascending by date)
     * @param  array $params Optional runtime parameters
     * @return array         Array of ['t','indicator','value','meta'] rows
     */
    public function compute(array $bars, array $params = []): array
    {
        // Normalize and merge options
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);

        // 🔧 Resolve lookback windows
        $window = (int)($opts['window'] ?? $opts['period'] ?? 10);
        $windows = (array)($opts['windows'] ?? [$window]);
        $percent = (bool)($opts['percent'] ?? false);

        $closes = array_column($bars, 'c');
        $rows   = [];

        foreach ($windows as $win) {
            $win = (int)$win;
            if ($win <= 0 || count($closes) <= $win) {
                continue;
            }

            for ($i = $win; $i < count($closes); $i++) {
                $prev = $closes[$i - $win];
                $curr = $closes[$i];

                if ($prev === 0.0 || $prev === null) {
                    $value = null;
                } else {
                    $value = $percent
                        ? (($curr / $prev) - 1) * 100
                        : ($curr - $prev);
                }

                $rows[] = [
                    't'         => $bars[$i]['t'],
                    'indicator' => "momentum_{$win}",
                    'value'     => $value !== null ? round($value, 6) : null,
                    'meta'      => null,
                ];
            }
        }

        return $rows;
    }
}