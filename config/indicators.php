<?php
/**
 * Indicator Configuration Map
 * 
 * Central policy map for indicator storage, caching, and computation.
 *
 * This file governs the hybrid data model for **TickerWolf.ai**:
 * - Defines which indicators are stored permanently in the database (DB).
 * - Defines which are computed on-the-fly and cached in memory.
 * - Defines which are serialized into long-term feature snapshots (for AI/ML).
 *
 * The FeaturePipeline, FeatureSnapshotBuilder, and related compute jobs
 * reference this configuration to determine:
 *   - What to persist in `ticker_indicators`
 *   - What to include in `ticker_feature_snapshots`
 *   - What to compute live (and optionally cache)
 *
 * 🔧 Philosophy:
 *   - **Core indicators** (ATR, MACD, ADX, VWAP) → stored daily in DB.
 *   - **Composite / derived indicators** (Momentum, Volatility, Sharpe) → computed weekly and embedded in snapshots.
 *   - **Lightweight indicators** (SMA, EMA, RSI, etc.) → computed on-demand and cached for UI speed.
 *
 * Each section below maps directly to one layer of the system.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Core Storage Policy
    |--------------------------------------------------------------------------
    |
    | Defines which indicators are routed to each destination layer.
    | Each key corresponds to a storage "tier" in the hybrid data model.
    |
    | ───────────────────────────────────────────────────────────────
    | 1. ticker_indicators         → High-value, heavy, reused metrics
    | 2. ticker_feature_snapshots  → Aggregated, serialized AI features
    | 3. cache_only                → Computed dynamically, cached in memory
    | 4. on_demand                 → Computed ad-hoc, no caching or persistence
    | ───────────────────────────────────────────────────────────────
    |
    | ⚙️ Conventions:
    | - Each key listed maps to an Indicator class in `App\Services\Compute\Indicators`.
    | - Module shortnames (e.g., "sma", "momentum") match `$name` properties in those classes.
    */

    'storage' => [

        // ───────────────────────────────────────────────────────────────
        // 1️⃣ CORE INDICATORS (DB-persisted daily)
        // ───────────────────────────────────────────────────────────────
        // Heavier, foundational metrics reused across multiple systems.
        // These are precomputed by batch jobs and stored in `ticker_indicators`.
        'ticker_indicators' => [
            'macd',   // EMA-based convergence/divergence indicator
            'atr',    // Average True Range (volatility baseline)
            'adx',    // Average Directional Index (trend strength)
            'vwap',   // Volume-weighted average price (intraday anchor)
        ],

        // ───────────────────────────────────────────────────────────────
        // 2️⃣ FEATURE SNAPSHOTS (AI/ML data store, weekly aggregation)
        // ───────────────────────────────────────────────────────────────
        // Captures a comprehensive per-ticker feature vector as JSON.
        // Each snapshot is written to `ticker_feature_snapshots`.
        // Combines both stored and computed indicators.
        'ticker_feature_snapshots' => [
            'macd',
            'atr',
            'adx',
            'vwap',
            'beta',
            'sharpe',
            'drawdown',
            'momentum',   // computed on-the-fly during snapshot builds
            'volatility', // computed on-the-fly during snapshot builds
        ],

        // ───────────────────────────────────────────────────────────────
        // 3️⃣ CACHED INDICATORS (in-memory cache, recomputed daily)
        // ───────────────────────────────────────────────────────────────
        // Used for UI visualization and dynamic analytics panels.
        // Stored in memory via Cache facade for fast lookups.
        'cache_only' => [
            'sma',         // simple moving average
            'ema',         // exponential moving average
            'rsi',         // relative strength index
            'bb',          // Bollinger Bands
            'stochastic',  // %K / %D oscillator
            'cci',         // commodity channel index
            'obv',         // on-balance volume
            'momentum',    // rate-of-change momentum (short-term velocity)
            'volatility',  // rolling std. deviation of returns
        ],

        // ───────────────────────────────────────────────────────────────
        // 4️⃣ EPHEMERAL / ON-DEMAND INDICATORS
        // ───────────────────────────────────────────────────────────────
        // Computed transiently for single-page analytics; never cached.
        // Add entries here for instant (non-persistent) analytics.
        'on_demand' => [
            // none by default — define here for ultra-fast transient computations
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Parameters
    |--------------------------------------------------------------------------
    |
    | Defines canonical defaults for each indicator type.
    | These ensure consistency across batch jobs, snapshot builds,
    | and live compute layers (controllers, APIs, etc.).
    |
    | All modules automatically merge these with runtime params,
    | ensuring reproducible results and parameter transparency.
    */

    'defaults' => [

        // Moving averages
        'sma' => [
            'windows' => [10, 20, 50, 200], // commonly used technical lengths
        ],
        'ema' => [
            'windows' => [12, 26, 50], // aligns with MACD defaults
        ],

        // Oscillators
        'rsi' => [
            'periods' => [7, 14], // short and standard RSI lengths
        ],
        'stochastic' => [
            'k' => 14, // fast period
            'd' => 3,  // smoothing
        ],
        'cci' => [
            'period' => 20, // default CCI window
        ],

        // Core indicators (DB-stored)
        'macd' => [
            'fast'   => 12,
            'slow'   => 26,
            'signal' => 9,
        ],
        'atr' => [
            'period' => 14,
        ],
        'adx' => [
            'period' => 14,
        ],
        'vwap' => [
            'period' => '1d',
        ],

        // Volatility, correlation, and risk metrics
        'beta' => [
            'period' => 60,
        ],
        'sharpe' => [
            'period' => 60,
        ],
        'volatility' => [
            'period' => 30,
        ],
        'drawdown' => [
            // no parameters needed (computed from historical highs/lows)
        ],

        // Volume-based metrics
        'obv' => [],
        'mfi' => [
            'period' => 14, // money flow index
        ],

        // ───────────────────────────────────────────────────────────────
        // 🧭 Momentum Configuration
        // ───────────────────────────────────────────────────────────────
        // Supports multiple interchangeable parameters for compatibility:
        // - "period"  → used by config defaults and CLI params
        // - "window"  → internal alias used by compute modules
        // - "windows" → allows multiple window computations in one pass
        // - "percent" → toggles between absolute and % change modes
        // Example output key: momentum_10
        'momentum' => [
            'period'  => 10,
            'window'  => 10,
            'windows' => [10],
            'percent' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Lifetimes (seconds)
    |--------------------------------------------------------------------------
    |
    | Defines expiration durations for each cache layer.
    | Determines how long cached or stored indicators remain valid
    | before automatic recomputation.
    |
    | These values are used by both the FeaturePipeline and controllers.
    |
    | Notes:
    | - `cache_only`     → transient cache used by UI components
    | - `ticker_indicators` → represents DB layer freshness window
    | - `ticker_feature_snapshots` → weekly rebuild cadence for ML layer
    */

    'cache_ttl' => [
        'cache_only'               => 60 * 60 * 24,        // 24 hours
        'ticker_indicators'        => 60 * 60 * 24 * 7,    // 1 week
        'ticker_feature_snapshots' => 60 * 60 * 24 * 7,    // 1 week
    ],

    /*
    |--------------------------------------------------------------------------
    | Refresh Schedules
    |--------------------------------------------------------------------------
    |
    | Defines the target recomputation cadence for each layer.
    | Used by the scheduler and batch job dispatchers to decide
    | when to refresh stored data and regenerate features.
    |
    | Example:
    | - Core indicators: rebuilt daily at market close.
    | - Feature snapshots: rebuilt weekly for AI ingestion.
    */

    'refresh_intervals' => [
        'ticker_indicators'        => 'daily',
        'ticker_feature_snapshots' => 'weekly',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI / LLM Feature Integration
    |--------------------------------------------------------------------------
    |
    | Specifies which indicators contribute numeric values
    | to AI feature vectors and embedding pipelines.
    |
    | These metrics are prioritized during feature export
    | for downstream ML models, LLM context, and analytics APIs.
    |
    | Include only indicators that are numerically stable,
    | widely available, and relevant to trend/momentum modeling.
    */

    'ai_features' => [
        'macd',        // momentum via EMA convergence/divergence
        'rsi',         // short-term momentum oscillator
        'adx',         // trend strength
        'atr',         // volatility baseline
        'volatility',  // statistical volatility
        'momentum',    // raw price rate-of-change
        'beta',        // market correlation coefficient
        'sharpe',      // risk-adjusted return
        'drawdown',    // peak-to-trough loss metric
    ],
];