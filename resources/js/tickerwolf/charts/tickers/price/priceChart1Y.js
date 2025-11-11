/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — 1-Year Price Chart
 * --------------------------------------------------------------------------
 * Displays one year of price data with smooth transitions, unified tooltip
 * styling, and consistent font-family across light/dark themes.
 *
 * Now uses the centralized chart configuration helper:
 *   ➤ resources/js/tickerwolf/charts/_core/chart-styles.js
 *
 * Benefits:
 *   • Consistent font and color styling across all charts
 *   • Centralized tooltip logic and date handling
 *   • Simplified maintenance and smaller code footprint
 * --------------------------------------------------------------------------
 */

import ApexCharts from 'apexcharts'
import { buildChartOptions } from '../../_core/chart-styles.js'

/**
 * Render the 1-Year Price Chart.
 *
 * @param {string|HTMLElement} selector
 *   DOM selector or node where the chart should render (e.g. '#price-chart')
 *
 * @param {Array<{x:string|number|Date, y:number|null}>} series
 *   Array of `{x:'YYYY-MM-DD', y:close}` points (downsampled server-side).
 *
 * @param {Object} [options={}]
 *   Optional configuration overrides (e.g. `{ currency:'USD' }`).
 */
export function renderPriceChart(selector, series = [], options = {}) {
  const el = typeof selector === 'string' ? document.querySelector(selector) : selector
  if (!el || !series.length) {
    console.warn('[PriceChart:1Y] No target element or empty data series.')
    return null
  }

  // ------------------------------------------------------------------------
  // Build the chart configuration using our shared helper.
  // The 1-year chart shows ~6 month-spaced ticks and formats tooltip dates
  // as "Nov 10, 2025" for clarity and consistency across devices.
  // ------------------------------------------------------------------------
  const opts = buildChartOptions({
    series: [{ name: 'Price', data: series }],
    timeframe: '1Y',
    tickAmount: 6,
    tooltipFormat: {
      month: 'short',
      day: '2-digit',
      year: 'numeric',
    },
    axisTimeFormat: { month: 'short' },
    currency: options.currency || 'USD',
  })

  // ------------------------------------------------------------------------
  // Instantiate and render the ApexChart instance.
  // ------------------------------------------------------------------------
  const chart = new ApexCharts(el, opts)
  chart.render()
  return chart
}