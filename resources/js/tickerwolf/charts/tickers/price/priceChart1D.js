/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — 1-Day Price Chart
 * --------------------------------------------------------------------------
 * Displays a single day’s price movement (or last 24h, if intraday data
 * is later added). Uses the unified chart style builder for consistent
 * visuals, tooltip formatting, and typography across all range charts.
 *
 * Time granularity is supported (hour/minute precision) while preserving
 * the same theme integration and smooth gradient area styling.
 * --------------------------------------------------------------------------
 */

import ApexCharts from 'apexcharts'
import { buildChartOptions } from '../../_core/chart-styles.js'

/**
 * Render the 1-Day Price Chart.
 *
 * @param {string|HTMLElement} selector
 *   DOM selector or node where the chart should render (e.g. '#price-chart').
 *
 * @param {Array<{x:string|number|Date, y:number|null}>} series
 *   Array of `{x:'YYYY-MM-DDTHH:mm:ss', y:close}` points.
 *   May contain daily or intraday timestamps.
 *
 * @param {Object} [options={}]
 *   Optional overrides (e.g. `{ currency:'USD' }`).
 */
export function renderPriceChart(selector, series = [], options = {}) {
  const el = typeof selector === 'string' ? document.querySelector(selector) : selector
  if (!el || !series.length) {
    console.warn('[PriceChart:1D] No target element or empty data series.')
    return null
  }

  // ------------------------------------------------------------------------
  // Build the chart configuration for intraday or single-day display.
  // The tooltip and x-axis use detailed "MMM DD, HH:mm" time formatting.
  // ------------------------------------------------------------------------
  const opts = buildChartOptions({
    series: [{ name: 'Price', data: series }],
    timeframe: '1D',
    tickAmount: 6,
    tooltipFormat: {
      month: 'short',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    },
    // 1-day charts can show hours on the x-axis for intraday resolution
    axisTimeFormat: { hour: '2-digit', minute: '2-digit' },
    currency: options.currency || 'USD',
  })

  // ------------------------------------------------------------------------
  // Instantiate and render the ApexChart instance.
  // ------------------------------------------------------------------------
  const chart = new ApexCharts(el, opts)
  chart.render()
  return chart
}