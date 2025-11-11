/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — 1-Week Price Chart
 * --------------------------------------------------------------------------
 * Displays the past week of prices (typically 5–7 daily points) using
 * a smooth gradient area, right-aligned y-axis, and unified tooltip styling.
 *
 * This version uses the centralized configuration helper:
 *   ➤ resources/js/tickerwolf/charts/_core/chart-styles.js
 *
 * Advantages:
 *   • Unified colors, fonts, grid, and tooltip styles
 *   • Simplified maintenance and consistent theming
 *   • Automatically supports dark mode + font updates
 * --------------------------------------------------------------------------
 */

import ApexCharts from 'apexcharts'
import { buildChartOptions } from '../../_core/chart-styles.js'

/**
 * Render the 1-Week Price Chart.
 *
 * @param {string|HTMLElement} selector
 *    DOM selector or node where the chart will render (e.g. '#price-chart')
 *
 * @param {Array<{x:string|number|Date, y:number|null}>} series
 *    Array of `{x:'YYYY-MM-DD', y:close}` points.
 *    The dataset should already be daily for this range.
 *
 * @param {Object} [options={}]
 *    Optional overrides (e.g. `{ currency:'USD' }`).
 */
export function renderPriceChart(selector, series = [], options = {}) {
  const el = typeof selector === 'string' ? document.querySelector(selector) : selector
  if (!el || !series.length) {
    console.warn('[PriceChart:1W] No target element or empty data series.')
    return null
  }

  // ------------------------------------------------------------------------
  // Build the chart configuration.
  // The 1-week view uses short "MMM DD" date labels
  // and a modest 6 tick marks for legibility.
  // ------------------------------------------------------------------------
  const opts = buildChartOptions({
    series: [{ name: 'Price', data: series }],
    timeframe: '1W',
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