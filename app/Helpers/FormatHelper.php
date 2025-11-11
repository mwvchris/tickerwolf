<?php

namespace App\Helpers;

/**
 * Formatting helpers for financial numbers, percentages, volumes, etc.
 */
class FormatHelper
{
    /**
     * Format a number as currency, e.g., 1234.56 -> "$1,234.56"
     *
     * @param float|null $value
     * @param string     $symbol   (optional)
     * @return string
     */
    public static function currency(?float $value, string $symbol = '$'): string
    {
        if ($value === null) {
            return '—';
        }
        return $symbol . number_format($value, 2, '.', ',');
    }

    /**
     * Format percent change, e.g., 5.324 -> "+5.32%"
     * @param float|null $value
     * @return string
     */
    public static function percent(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        $sign = $value >= 0 ? '+' : '';
        return $sign . number_format($value, 2) . '%';
    }

    /**
     * Human-read volume numbers, e.g., 1200000 -> "1.2M"
     *
     * @param float|int|null $value
     * @return string
     */
    public static function humanVolume($value): string
    {
        if ($value === null) {
            return '—';
        }
        if ($value >= 1000000000) {
            return number_format($value / 1000000000, 1) . 'B';
        } elseif ($value >= 1000000) {
            return number_format($value / 1000000, 1) . 'M';
        } elseif ($value >= 1000) {
            return number_format($value / 1000, 1) . 'K';
        }
        return (string)$value;
    }
}