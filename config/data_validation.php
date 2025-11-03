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
];