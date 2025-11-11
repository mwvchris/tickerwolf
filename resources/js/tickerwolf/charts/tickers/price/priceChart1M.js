/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — 1-Month Price Chart
 * --------------------------------------------------------------------------
 * Displays roughly 30 trading days of data with:
 *   - Smooth line/area transitions
 *   - Right-aligned y-axis with currency formatting
 *   - 4–6 x-axis labels for date readability
 *   - Hover-only tooltips styled via shared chart-styles.js + global CSS
 *
 * This file now delegates its core configuration to the shared helper:
 *   ➤ resources/js/tickerwolf/charts/_core/chart-styles.js
 *
 * Benefits:
 *   - Ensures unified fonts, colors, gradients, and tooltips
 *   - Eliminates duplication across chart range files (1D, 6M, 1Y, etc.)
 *   - Makes future design updates instant across all charts
 * --------------------------------------------------------------------------
 */

import ApexCharts from 'apexcharts'
import { buildChartOptions } from '../../_core/chart-styles.js'

/**
 * Render the 1-Month price chart.
 *
 * @param {string|HTMLElement} selector
 *    DOM selector or node where the chart will render (e.g. '#price-chart')
 *
 * @param {Array<{x:string|number|Date, y:number|null}>} series
 *    Lightweight array of `{x:'YYYY-MM-DD', y:close}` points.
 *    The data is already downsampled server-side for speed.
 *
 * @param {Object} [options={}]
 *    Optional overrides (e.g. `{ currency:'USD' }`).
 */
export function renderPriceChart(selector, series = [], options = {}) {
  // Resolve the container element safely.
  const el = typeof selector === 'string' ? document.querySelector(selector) : selector
  if (!el || !series.length) {
    console.warn('[PriceChart:1M] No target element or empty data series.')
    return null
  }

  // ------------------------------------------------------------------------
  // Build shared chart configuration from the centralized helper.
  // We only specify what’s unique to this timeframe:
  //   - 6 x-axis labels
  //   - short "MMM DD" tooltip and axis formatting
  // ------------------------------------------------------------------------
  const opts = buildChartOptions({
    series: [{ name: 'Price', data: series }],
    timeframe: '1M',
    tickAmount: 6,
    tooltipFormat: { month: 'short', day: '2-digit' },
    currency: options.currency || 'USD',
  })

  // ------------------------------------------------------------------------
  // Instantiate and render the ApexChart instance.
  // ------------------------------------------------------------------------
  const chart = new ApexCharts(el, opts)
  chart.render()
  return chart
}