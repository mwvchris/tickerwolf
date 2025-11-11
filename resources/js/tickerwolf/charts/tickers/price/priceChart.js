/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — Price Chart Controller (Backend-Driven)
 * --------------------------------------------------------------------------
 * The backend (TickerController + Ticker model) already performs
 * all data shaping and downsampling for each range. This controller’s
 * sole job is to:
 *   - Select the correct chart renderer module (1D, 1W, 1M, 6M, 1Y, 5Y)
 *   - Apply consistent tooltip + axis formatting via formatters.js
 *   - Re-render when the UI theme changes
 *
 * No client-side downsampling is performed here.
 * --------------------------------------------------------------------------
 */

import { onChartThemeChange } from '../../_core/theme.js';
import { number as fmtNumber, date as fmtDate } from '../../_core/formatters.js';
import { currencyFormatter, shortDate } from '../../_core/utils.js';

/**
 * Initialize the interactive price chart with range toggles.
 *
 * @param {string} selector      DOM selector for chart container
 * @param {Object} datasets      Map of range → array of {x:'YYYY-MM-DD', y:Number}
 * @param {string} defaultRange  Default range to render (e.g. '1M')
 */
export function initPriceChart(selector, datasets = {}, defaultRange = '1M') {
  const container = document.querySelector(selector);
  if (!container) {
    console.warn('[PriceChart] Container not found:', selector);
    return;
  }

  let currentRange = defaultRange;
  let currentChart = null;

  /**
   * Dynamically import and render the appropriate chart module.
   * The data is already preprocessed on the backend.
   */
  async function renderChart(range) {
    const data = datasets[range] || [];
    if (!Array.isArray(data) || data.length === 0) {
      console.warn(`[PriceChart] Empty or invalid dataset for range: ${range}`);
      return;
    }

    // Sanity check for suspiciously small datasets (likely double-downsampled)
    if (data.length < 10 && (range === '1Y' || range === '5Y')) {
      console.warn(
        `[PriceChart] Warning: only ${data.length} points for ${range}. ` +
        `Verify backend downsampling thresholds.`
      );
    }

    // Determine module path by range
    let modulePath = './priceChart1M.js';
    switch (range) {
      case '1D': modulePath = './priceChart1D.js'; break;
      case '1W': modulePath = './priceChart1W.js'; break;
      case '6M': modulePath = './priceChart6M.js'; break;
      case '1Y': modulePath = './priceChart1Y.js'; break;
      case '5Y': modulePath = './priceChart5Y.js'; break;
      default:   modulePath = './priceChart1M.js'; break;
    }

    try {
      const module = await import(modulePath);

      // Tear down old chart cleanly
      if (currentChart && typeof currentChart.destroy === 'function') {
        currentChart.destroy();
      }

      // Render new chart
      currentChart = module.renderPriceChart(selector, data, {
        range,
        currency: 'USD',
        formatters: { fmtNumber, fmtDate, currencyFormatter, shortDate },
      });

      currentRange = range;
    } catch (err) {
      console.error(`[PriceChart] Failed to import module for ${range}:`, err);
    }
  }

  // Handle range toggle buttons
  const rangeButtons = document.querySelectorAll('[data-chart-range]');
  if (rangeButtons.length) {
    rangeButtons.forEach((btn) => {
      btn.addEventListener('click', (e) => {
        const range = e.currentTarget.dataset.chartRange;
        if (range && range !== currentRange) {
          rangeButtons.forEach((b) => b.classList.remove('active'));
          e.currentTarget.classList.add('active');
          renderChart(range);
        }
      });
    });
  }

  // Initial render
  renderChart(defaultRange);

  // Re-render on theme change (dark/light)
  onChartThemeChange(() => {
    if (container && container.isConnected) renderChart(currentRange);
  });
}