/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — Chart Loader & Lifecycle Helper
 * --------------------------------------------------------------------------
 * Provides lazy-loading, automatic cleanup, and reinitialization behavior
 * for all chart instances. Especially useful for pages containing many charts.
 * --------------------------------------------------------------------------
 */

import ApexCharts from "apexcharts";

/**
 * Mount an ApexChart lazily (only when visible in the viewport)
 *
 * @param {HTMLElement|string} el
 * @param {Object} config
 */
export function lazyRenderChart(el, config) {
  const element = typeof el === "string" ? document.querySelector(el) : el;
  if (!element) return;

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const chart = new ApexCharts(element, config);
        chart.render();
        observer.unobserve(element); // Stop observing after initial render
      }
    });
  }, { threshold: 0.2 });

  observer.observe(element);
}

/**
 * Destroy all charts in a container — useful for SPA navigation cleanup.
 */
export function destroyCharts(containerSelector = "body") {
  const charts = ApexCharts.exec();
  charts?.forEach(id => ApexCharts.exec(id, "destroy"));
}