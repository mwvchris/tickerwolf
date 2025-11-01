<?php

namespace App\Services\Compute;

use App\Services\Compute\Indicators\SMAIndicator;
use App\Services\Compute\Indicators\EMAIndicator;
use App\Services\Compute\Indicators\RSIIndicator;
use App\Services\Compute\Indicators\MACDIndicator;
use App\Services\Compute\Indicators\ATRIndicator;
use App\Services\Compute\Indicators\BollingerIndicator;
use App\Services\Compute\Indicators\VWAPIndicator;
use App\Services\Compute\Indicators\MomentumIndicator;
use App\Services\Compute\Indicators\StochasticIndicator;
use App\Services\Compute\Indicators\CCIIndicator;
use App\Services\Compute\Indicators\ADXIndicator;
use App\Services\Compute\Indicators\OBVIndicator;
use App\Services\Compute\Indicators\MFIIndicator;
use App\Services\Compute\Indicators\BetaIndicator;
use App\Services\Compute\Indicators\SharpeRatioIndicator;
use App\Services\Compute\Indicators\DrawdownIndicator;
use App\Services\Compute\Indicators\VolatilityIndicator;
use App\Services\Compute\Indicators\RollingCorrelationIndicator;
use App\Services\Compute\Indicators\RollingBetaIndicator;
use App\Services\Compute\Indicators\R2Indicator;

/**
 * Class Registry
 *
 * Central registry that declares which indicator modules are currently active.
 *
 * Responsibilities:
 * - Acts as a discovery hub for all computational indicators.
 * - Decouples pipeline orchestration (FeaturePipeline) from specific implementations.
 * - Makes it simple to add, remove, or toggle entire indicator classes.
 * - Provides dynamic lookup of indicators via their `$name` property.
 *
 * Extending the registry:
 * - When adding a new indicator file under Compute/Indicators, import and append it to activeIndicators().
 * - Use descriptive `displayName` in indicator classes for clarity in logs/UI.
 */
class Registry
{
    /**
     * Return all active indicator module instances.
     *
     * Note:
     * - Each module is stateless; new instances can be safely reused.
     * - You can disable modules by commenting them out if you want
     *   to temporarily remove them from the pipeline.
     *
     * @return array<BaseIndicator>
     */
    public static function activeIndicators(): array
    {
        return [
            // Core trend and moving average indicators
            new SMAIndicator(),
            new EMAIndicator(),
            new RSIIndicator(),
            new MACDIndicator(),
            new ATRIndicator(),
            new BollingerIndicator(),
            new VWAPIndicator(),
            new RollingCorrelationIndicator(),
            new RollingBetaIndicator(),
            new R2Indicator(),

            // Momentum / Oscillator family
            new MomentumIndicator(),
            new StochasticIndicator(),
            new CCIIndicator(),
            new ADXIndicator(),

            // Volume-based indicators
            new OBVIndicator(),
            new MFIIndicator(),

            // Risk and market-relative indicators
            new BetaIndicator(),
            new SharpeRatioIndicator(),
            new DrawdownIndicator(),
            new VolatilityIndicator(),
        ];
    }

    /**
     * Select a subset of indicators by short name.
     *
     * Example:
     *   Registry::select(['sma', 'rsi', 'macd']);
     *
     * Returns:
     *   [ SMAIndicator, RSIIndicator, MACDIndicator ]
     *
     * Behavior:
     * - Ignores unknown names to ensure pipeline safety.
     * - Case-insensitive and whitespace-tolerant.
     *
     * @param array<string> $names
     * @return array<BaseIndicator>
     */
    public static function select(array $names): array
    {
        $map = [];
        foreach (self::activeIndicators() as $mod) {
            $map[strtolower($mod->name)] = $mod;
        }

        $out = [];
        foreach ($names as $name) {
            $k = strtolower(trim($name));
            if (isset($map[$k])) {
                $out[] = $map[$k];
            }
        }

        return $out;
    }
}