<?php
/**
 * ============================================================================
 *  Ticker Data Validation Configuration
 * ============================================================================
 * Centralized thresholds and weighting for ticker integrity scoring.
 * ============================================================================
 */
return [

    // Gaps: maximum days allowed between consecutive bars (excl. weekends/holidays)
    'gap_days_tolerance' => 10,

    // Flatlines: maximum consecutive days of identical closes before flagging
    'flat_streak_tolerance' => 5,

    // Spikes: absolute daily return threshold before flagging (e.g., 0.5 = ±50%)
    'spike_threshold' => 0.5,

    // Relative weighting for anomaly impact on health score
    'weights' => [
        'gaps'   => 0.4,
        'flat'   => 0.3,
        'spikes' => 0.3,
    ],

    // Minimum number of bars required before meaningful validation
    'min_bars' => 10,

    // Data completeness thresholds (used by integrity scan)
    'coverage_thresholds' => [
        'moderate' => 75,   // below this => "partial"
        'critical' => 25,   // below this => "very sparse"
    ],

    // Liquidity thresholds (used by integrity scan)
    'liquidity' => [
        'min_avg_volume' => 100, // below this => "illiquid"
    ],

    // Optional flatness tolerance override (integrity scan)
    'flat_ratio_threshold' => 0.5, // >50% flat bars = 'flat'

    // Label display preferences (used for console reporting)
    'label_colors' => [
        'critical' => 'red',
        'moderate' => 'yellow',
        'healthy'  => 'green',
    ],
];