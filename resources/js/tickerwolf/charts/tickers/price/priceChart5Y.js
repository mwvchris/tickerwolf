/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — 5-Year Price Chart
 * --------------------------------------------------------------------------
 * Displays long-term (multi-year) performance data, down-sampled to roughly
 * weekly intervals by the backend for efficiency. Uses unified chart styles
 * and typography to ensure visual consistency across all ranges.
 *
 * This version leverages the centralized chart configuration helper:
 *   ➤ resources/js/tickerwolf/charts/_core/chart-styles.js
 *
 * Benefits:
 *   • Unified font-family and color scheme
 *   • Simplified code structure and maintainability
 *   • Consistent tooltip and axis date formatting
 * --------------------------------------------------------------------------
 */

import ApexCharts from 'apexcharts'
import { buildChartOptions } from '../../_core/chart-styles.js'

/**
 * Render the 5-Year Price Chart.
 *
 * @param {string|HTMLElement} selector
 *   DOM selector or node where the chart should render (e.g. '#price-chart')
 *
 * @param {Array<{x:string|number|Date, y:number|null}>} series
 *   Array of `{x:'YYYY-MM-DD', y:close}` points (already downsampled).
 *
 * @param {Object} [options={}]
 *   Optional overrides (e.g. `{ currency:'USD' }`).
 */
export function renderPriceChart(selector, series = [], options = {}) {
  const el = typeof selector === 'string' ? document.querySelector(selector) : selector
  if (!el || !series.length) {
    console.warn('[PriceChart:5Y] No target element or empty data series.')
    return null
  }

  // ------------------------------------------------------------------------
  // Build the chart configuration for a 5-year view.
  // The x-axis shows ~6 evenly spaced labels like “Jan 2021”, “Jan 2022”, etc.
  // Tooltip displays full "Month YYYY" for clarity on long-term timeframes.
  // ------------------------------------------------------------------------
  const opts = buildChartOptions({
    series: [{ name: 'Price', data: series }],
    timeframe: '5Y',
    tickAmount: 6,
    tooltipFormat: {
      month: 'short',
      year: 'numeric',
    },
    axisTimeFormat: {
      month: 'short',
      year: 'numeric',
    },
    currency: options.currency || 'USD',
  })

  // ------------------------------------------------------------------------
  // Instantiate and render the ApexChart instance.
  // ------------------------------------------------------------------------
  const chart = new ApexCharts(el, opts)
  chart.render()
  return chart
}