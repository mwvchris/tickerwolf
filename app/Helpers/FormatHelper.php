<?php

namespace App\Helpers;

/**
 * Formatting helpers for financial numbers, percentages, volumes, etc.
 */
class FormatHelper
{
    /**
     * Format a number as currency, e.g., 1234.56 -> "$1,234.56".
     *
     * @param  float|null  $value
     * @param  string      $symbol
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
     * Format a signed currency change, e.g., -1.23 -> "-$1.23", 1.23 -> "+$1.23".
     *
     * @param  float|null  $value
     * @param  string      $symbol
     * @return string
     */
    public static function signedCurrencyChange(?float $value, string $symbol = '$'): string
    {
        if ($value === null) {
            return '—';
        }

        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');
        $abs  = abs($value);

        return $sign . $symbol . number_format($abs, 2, '.', ',');
    }

    /**
     * Format percent change, e.g., 5.324 -> "+5.32%".
     *
     * @param  float|null  $value
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
     * Human-read volume numbers, e.g., 1200000 -> "1.2M".
     *
     * @param  float|int|null  $value
     * @return string
     */
    public static function humanVolume($value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value >= 1_000_000_000_000) {
            return number_format($value / 1_000_000_000_000, 1) . 'T';
        }

        if ($value >= 1_000_000_000) {
            return number_format($value / 1_000_000_000, 1) . 'B';
        }

        if ($value >= 1_000_000) {
            return number_format($value / 1_000_000, 1) . 'M';
        }

        if ($value >= 1_000) {
            return number_format($value / 1_000, 1) . 'K';
        }

        return (string) $value;
    }

    /**
     * Compact big currency values like 8_170_000_000 -> "$8.17B".
     *
     * @param  float|int|null  $value
     * @param  string          $symbol
     * @return string
     */
    public static function compactCurrency($value, string $symbol = '$'): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value >= 1_000_000_000_000) {
            return $symbol . number_format($value / 1_000_000_000_000, 2) . 'T';
        }
        

        if ($value >= 1_000_000_000) {
            return $symbol . number_format($value / 1_000_000_000, 2) . 'B';
        }

        if ($value >= 1_000_000) {
            return $symbol . number_format($value / 1_000_000, 2) . 'M';
        }

        if ($value >= 1_000) {
            return $symbol . number_format($value / 1_000, 2) . 'K';
        }

        return $symbol . number_format($value, 0);
    }

    /**
     * Compact pure numbers like 217_700_000 -> "217.7M".
     *
     * @param  float|int|null  $value
     * @return string
     */
    public static function compactNumber($value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value >= 1_000_000_000_000) {
            return number_format($value / 1_000_000_000_000, 1) . 'T';
        }

        if ($value >= 1_000_000_000) {
            return number_format($value / 1_000_000_000, 1) . 'B';
        }

        if ($value >= 1_000_000) {
            return number_format($value / 1_000_000, 1) . 'M';
        }

        if ($value >= 1_000) {
            return number_format($value / 1_000, 1) . 'K';
        }

        return number_format($value, 0);
    }
}