/**
 * --------------------------------------------------------------------------
 * _core/formatters.js
 * --------------------------------------------------------------------------
 * Date & number formatters shared by all chart modules.
 * We intentionally avoid importing external libs for performance.
 * --------------------------------------------------------------------------
 */

export const number = {
  /**
   * Format a price (e.g., 1234.5 -> "$1,234.50").
   * @param {number|null|undefined} v
   * @param {string} currency
   * @returns {string}
   */
  price(v, currency = "USD") {
    if (v === null || v === undefined) return "—";
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(v);
  },
};

export const date = {
  /**
   * Format YYYY-MM-DD into label suitable for x-axis
   * with varying detail depending on density.
   */
  axis(d) {
    // Keep nice short labels (e.g., "Nov 7")
    const dt = new Date(d + "T00:00:00");
    return dt.toLocaleDateString("en-US", { month: "short", day: "numeric" });
  },

  /**
   * Human-friendly tooltip date. When showTime=true, include time
   * (used for intraday/1D in the future if we add minute bars).
   */
  tooltip(d, showTime = false) {
    const dt = new Date(d + "T00:00:00");
    const dateStr = dt.toLocaleDateString("en-US", {
      weekday: "short",
      month: "short",
      day: "numeric",
      year: "numeric",
    });
    return showTime
      ? `${dateStr} ${dt.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" })}`
      : dateStr;
  },
};