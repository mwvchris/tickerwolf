<?php

namespace App\Helpers;

/**
 * Formatting helpers for financial numbers, percentages, volumes, etc.
 *
 * These utilities centralize UI formatting logic so controllers/models can
 * return clean primitives while Blade focuses purely on presentation.
 */
class FormatHelper
{
    /**
     * Format a number as currency, e.g., 1234.56 -> "$1,234.56".
     *
     * @param  float|null  $value
     * @param  string      $symbol
     * @return string
     *
     * @example
     * {{ \App\Helpers\FormatHelper::currency($ticker->last_close) }}
     */
    public static function currency(?float $value, string $symbol = '$'): string
    {
        if ($value === null) {
            return '—';
        }
        return $symbol . number_format($value, 2, '.', ',');
    }

    /**
     * Compact currency, e.g., 8_170_000_000 -> "$8.17B".
     *
     * @param  float|int|null  $value
     * @param  string          $symbol
     * @return string
     *
     * @example
     * {{ \App\Helpers\FormatHelper::compactCurrency($overview->market_cap) }}
     */
    public static function compactCurrency($value, string $symbol = '$'): string
    {
        if ($value === null) return '—';
        $abs = abs((float) $value);
        if ($abs >= 1_000_000_000) return $symbol . number_format($value / 1_000_000_000, 2) . 'B';
        if ($abs >= 1_000_000)     return $symbol . number_format($value / 1_000_000, 2) . 'M';
        if ($abs >= 1_000)         return $symbol . number_format($value / 1_000, 2) . 'K';
        return $symbol . number_format($value, 2);
    }

    /**
     * Format percent change, e.g., 5.324 -> "+5.32%".
     *
     * @param  float|null  $value
     * @return string
     *
     * @example
     * {{ \App\Helpers\FormatHelper::percent($ticker->day_change_pct) }}
     */
    public static function percent(?float $value): string
    {
        if ($value === null) return '—';
        $sign = $value >= 0 ? '+' : '';
        return $sign . number_format($value, 2) . '%';
    }

    /**
     * Signed currency change with prefix, e.g., +$1.23 / -$0.98.
     *
     * @param  float|null  $value
     * @param  string      $symbol
     * @return string
     *
     * @example
     * {{ \App\Helpers\FormatHelper::signedCurrencyChange($ticker->day_change_abs) }}
     */
    public static function signedCurrencyChange(?float $value, string $symbol = '$'): string
    {
        if ($value === null) return '—';
        $sign = $value >= 0 ? '+' : '-';
        return $sign . $symbol . number_format(abs($value), 2);
    }

    /**
     * Human-read volume numbers, e.g., 1200000 -> "1.2M".
     *
     * @param  float|int|null  $value
     * @return string
     *
     * @example
     * {{ \App\Helpers\FormatHelper::humanVolume($ticker->volume_latest) }}
     */
    public static function humanVolume($value): string
    {
        if ($value === null) return '—';
        $value = (float) $value;
        if ($value >= 1_000_000_000) return number_format($value / 1_000_000_000, 1) . 'B';
        if ($value >= 1_000_000)     return number_format($value / 1_000_000, 1) . 'M';
        if ($value >= 1_000)         return number_format($value / 1_000, 1) . 'K';
        return number_format($value, 0);
    }

    /**
     * Generic compact number, e.g., 217700000 -> "217.7M".
     *
     * @param  float|int|null  $value
     * @return string
     *
     * @example
     * {{ \App\Helpers\FormatHelper::compactNumber($overview->shares_outstanding) }}
     */
    public static function compactNumber($value): string
    {
        if ($value === null) return '—';
        $value = (float) $value;
        if ($value >= 1_000_000_000) return number_format($value / 1_000_000_000, 1) . 'B';
        if ($value >= 1_000_000)     return number_format($value / 1_000_000, 1) . 'M';
        if ($value >= 1_000)         return number_format($value / 1_000, 1) . 'K';
        return number_format($value, 0);
    }
}