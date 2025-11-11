/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — Dynamic Price Chart Controller
 * --------------------------------------------------------------------------
 * Centralized controller that renders time-range price charts (1D, 1W, 1M,
 * 6M, 1Y, 5Y) using ApexCharts. Uses lightweight `{x:'YYYY-MM-DD', y:Number}`
 * series for speed. On range toggle, destroys/rebuilds the chart smoothly.
 *
 * This file dynamically imports one of:
 *   - priceChart1D.js
 *   - priceChart1W.js
 *   - priceChart1M.js
 *   - priceChart6M.js
 *   - priceChart1Y.js
 *   - priceChart5Y.js
 *
 * Each of those exports:  renderPriceChart(selector, seriesArray)
 * where `seriesArray` is an array of `{ x: string|Date, y: number|null }`.
 * --------------------------------------------------------------------------
 */

import { onChartThemeChange } from '../../_core/theme.js';

/**
 * Initialize the price chart with range toggles and theme reactivity.
 *
 * @param {string} selector      DOM selector for chart container
 * @param {Object} datasets      Map of range -> series array (lightweight)
 *                               {
 *                                 '1M': [{x:'2025-01-01', y:123.45}, ...],
 *                                 '1Y': [...],
 *                               }
 * @param {string} defaultRange  Default range to show (e.g., '1M')
 */
export function initPriceChart(selector, datasets = {}, defaultRange = '1M') {
  const container = document.querySelector(selector);
  if (!container) {
    console.warn('[PriceChart] Container not found for selector:', selector);
    return;
  }

  let currentRange = defaultRange;
  let currentChart = null;

  /**
   * Dynamically import and render the appropriate chart module by range.
   * @param {string} range  One of '1D','1W','1M','6M','1Y','5Y'
   */
  async function renderChart(range) {
    const data = datasets[range] || [];
    if (!data.length) {
      console.warn(`[PriceChart] No series for range: ${range}`);
      return;
    }

    let modulePath = './priceChart1M.js';
    switch (range) {
      case '1D': modulePath = './priceChart1D.js'; break;
      case '1W': modulePath = './priceChart1W.js'; break;
      case '6M': modulePath = './priceChart6M.js'; break;
      case '1Y': modulePath = './priceChart1Y.js'; break;
      case '5Y': modulePath = './priceChart5Y.js'; break;
      case '1M':
      default:   modulePath = './priceChart1M.js'; break;
    }

    const module = await import(modulePath);

    if (currentChart) currentChart.destroy(); // smooth teardown
    currentChart = module.renderPriceChart(selector, data);
    currentRange = range;
  }

  // Wire up toggle buttons
  document.querySelectorAll('[data-chart-range]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      const range = e.currentTarget.dataset.chartRange;
      if (range && range !== currentRange) {
        document.querySelectorAll('[data-chart-range]').forEach((b) => b.classList.remove('active'));
        e.currentTarget.classList.add('active');
        renderChart(range);
      }
    });
  });

  // Initial render
  renderChart(defaultRange);

  // Re-render on theme change
  onChartThemeChange(() => {
    if (container && container.isConnected) renderChart(currentRange);
  });
}