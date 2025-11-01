<?php

namespace App\Services\Analytics;

use App\Services\Compute\BaseIndicator;

/**
 * BaseAnalytics
 *
 * Provides access to BaseIndicator math utilities (returns, variance, etc.)
 * without requiring `compute()` to be implemented.
 */
abstract class BaseAnalytics extends BaseIndicator
{
    public function compute(array $bars, array $params = []): array
    {
        // No-op, since analytics services use custom entry points.
        return [];
    }
}
