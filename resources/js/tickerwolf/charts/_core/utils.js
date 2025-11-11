/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — Chart Utility Helpers
 * --------------------------------------------------------------------------
 * Provides lightweight helper functions for formatting, conversions, and
 * data normalization shared across chart modules.
 * --------------------------------------------------------------------------
 */

/**
 * Format a number as currency (e.g., 1234.56 → "$1,234.56")
 */
export function currencyFormatter(val) {
  if (val === null || val === undefined || isNaN(val)) return "—";
  return `$${Number(val).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

/**
 * Format a number with thousands separators (for volumes, counts, etc.)
 */
export function numberFormatter(val) {
  if (val === null || val === undefined || isNaN(val)) return "—";
  return Number(val).toLocaleString();
}

/**
 * Convert a timestamp or ISO date string into a readable "MMM DD" format.
 * Example: "2025-11-09" → "Nov 09"
 */
export function shortDate(val) {
  if (!val) return "";
  const date = new Date(val);
  return date.toLocaleDateString("en-US", { month: "short", day: "2-digit" });
}