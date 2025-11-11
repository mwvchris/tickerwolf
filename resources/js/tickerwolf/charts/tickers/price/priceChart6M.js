/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — 6-Month Price Chart
 * --------------------------------------------------------------------------
 * Displays the last 6 months of price data with smooth transitions,
 * ~6 x-axis labels, right-side y-axis, and consistent theme styling.
 *
 * This version uses the centralized chart configuration helper:
 *   ➤ resources/js/tickerwolf/charts/_core/chart-styles.js
 *
 * Benefits:
 *   - Unified styling (fonts, gradients, grid, tooltip) across all charts
 *   - Single source of truth for theme/font/color logic
 *   - Automatic dark-mode compatibility
 * --------------------------------------------------------------------------
 */

import ApexCharts from 'apexcharts'
import { buildChartOptions } from '../../_core/chart-styles.js'

/**
 * Render the 6-Month Price Chart.
 *
 * @param {string|HTMLElement} selector
 *    DOM selector or node where the chart will render (e.g. '#price-chart')
 *
 * @param {Array<{x:string|number|Date, y:number|null}>} series
 *    Lightweight array of `{x:'YYYY-MM-DD', y:close}` points.
 *    Data is already downsampled server-side for speed.
 *
 * @param {Object} [options={}]
 *    Optional overrides (e.g. `{ currency:'USD' }`).
 */
export function renderPriceChart(selector, series = [], options = {}) {
  const el = typeof selector === 'string' ? document.querySelector(selector) : selector
  if (!el || !series.length) {
    console.warn('[PriceChart:6M] No target element or empty data series.')
    return null
  }

  // ------------------------------------------------------------------------
  // Build the shared chart configuration.
  // This specifies what’s unique for the 6-month view:
  //   • tickAmount: ~6 evenly spaced labels
  //   • tooltipFormat: "MMM DD" (e.g. Nov 10)
  // ------------------------------------------------------------------------
  const opts = buildChartOptions({
    series: [{ name: 'Price', data: series }],
    timeframe: '6M',
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