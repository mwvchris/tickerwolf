/**
 * ============================================================
 *  File: resources/js/tickerwolf/charts/tickers/price/priceVolumeChart.js
 *  ------------------------------------------------------------------------
 *  Purpose:
 *    Renders a 30-day dual-axis chart showing:
 *      • Daily closing price (purple bars)
 *      • Daily trading volume (light blue columns)
 *
 *  Frameworks:
 *    - Tailwind CSS (Lineone color palette)
 *    - ApexCharts.js (chart rendering)
 *
 *  Dependencies:
 *    Ensure ApexCharts is included in your Vite build.
 *    Example import (if globally bundled in `resources/js/lineone/libs/components.js`):
 *       import ApexCharts from "apexcharts";
 *
 *  Usage:
 *    import { renderPriceVolumeChart } from '/resources/js/tickerwolf/charts/tickers/price/priceVolumeChart.js';
 *    renderPriceVolumeChart('#price-volume-chart', chartData);
 *
 *  Expected chartData shape:
 *    [
 *      { date: '2025-10-10', open: 14.12, high: 15.22, low: 13.87, close: 14.78, volume: 2500431 },
 *      ...
 *    ]
 * ============================================================
 */

import ApexCharts from "apexcharts";

/**
 * Main exported function to render the price/volume chart.
 *
 * @param {string} selector - CSS selector of the container element (e.g. '#price-volume-chart')
 * @param {Array<Object>} data - Array of OHLCV data points
 */
export function renderPriceVolumeChart(selector, data = []) {
  // ------------------------------------------------------------------------
  // Safety check — exit if container or data missing
  // ------------------------------------------------------------------------
  const container = document.querySelector(selector);
  if (!container) {
    console.warn(`[PriceVolumeChart] Container not found: ${selector}`);
    return;
  }
  if (!data || !Array.isArray(data) || data.length === 0) {
    console.warn(`[PriceVolumeChart] No data provided for ${selector}`);
    container.innerHTML =
      '<p class="text-sm text-slate-400 text-center py-8">No chart data available.</p>';
    return;
  }

  if (container._chart) {
    container._chart.destroy();
    container._chart = null;
  }

  // ------------------------------------------------------------------------
  // Data Preparation
  // ------------------------------------------------------------------------
  // Extract separate arrays for chart series.
  const dates = data.map((d) => d.date);
  const closes = data.map((d) => d.close);
  const volumes = data.map((d) => d.volume);

  // Determine min/max for dynamic Y-axis scaling
  const minPrice = Math.min(...closes);
  const maxPrice = Math.max(...closes);

  // ------------------------------------------------------------------------
  // ApexCharts Configuration
  // ------------------------------------------------------------------------
  const options = {
    chart: {
      type: "bar",
      height: 400,
      toolbar: { show: false },
      foreColor: "#94a3b8", // Tailwind slate-400 for axes/labels
      fontFamily: "Inter, sans-serif",
      animations: {
        enabled: true,
        easing: "easeinout",
        speed: 600,
      },
    },

    // ----------------------------------------------------------------------
    // Series Definition (two y-axes)
    // ----------------------------------------------------------------------
    series: [
      {
        name: "Closing Price",
        type: "column",
        data: closes,
      },
      {
        name: "Volume",
        type: "area",
        data: volumes,
      },
    ],

    // ----------------------------------------------------------------------
    // Dual-Axis Configuration
    // ----------------------------------------------------------------------
    yaxis: [
      {
        title: {
          text: "Price (USD)",
          style: { color: "#a855f7", fontWeight: 500 },
        },
        labels: {
          formatter: (val) => `$${val.toFixed(2)}`,
        },
        min: Math.floor(minPrice * 0.98),
        max: Math.ceil(maxPrice * 1.02),
      },
      {
        opposite: true,
        title: {
          text: "Volume",
          style: { color: "#38bdf8", fontWeight: 500 },
        },
        labels: {
          formatter: (val) => {
            if (val >= 1_000_000_000) return (val / 1_000_000_000).toFixed(1) + "B";
            if (val >= 1_000_000) return (val / 1_000_000).toFixed(1) + "M";
            if (val >= 1_000) return (val / 1_000).toFixed(1) + "K";
            return val.toFixed(0);
          },
        },
      },
    ],

    // ----------------------------------------------------------------------
    // X-Axis Configuration
    // ----------------------------------------------------------------------
    xaxis: {
      categories: dates,
      type: "category",
      labels: {
        show: true,
        rotate: -45,
        hideOverlappingLabels: true,
        style: {
          colors: "#94a3b8",
          fontSize: "11px",
        },
      },
      tooltip: { enabled: false },
    },

    // ----------------------------------------------------------------------
    // Visual Styling
    // ----------------------------------------------------------------------
    colors: ["#a855f7", "#38bdf8"], // purple + light blue
    stroke: {
      width: [0, 2],
      curve: "smooth",
    },
    fill: {
      opacity: [0.9, 0.15],
      gradient: {
        shade: "light",
        type: "vertical",
        opacityFrom: 0.45,
        opacityTo: 0.05,
      },
    },
    dataLabels: { enabled: false },
    grid: {
      borderColor: "#e2e8f0",
      strokeDashArray: 3,
      yaxis: { lines: { show: true } },
    },
    tooltip: {
      shared: true,
      intersect: false,
      theme: document.documentElement.classList.contains("dark")
        ? "dark"
        : "light",
      y: {
        formatter: (val, opts) => {
          if (opts.seriesIndex === 0) return `$${val.toFixed(2)}`;
          return val.toLocaleString();
        },
      },
    },
    legend: {
      show: true,
      position: "top",
      horizontalAlign: "right",
      fontSize: "12px",
      labels: {
        colors: document.documentElement.classList.contains("dark")
          ? "#cbd5e1"
          : "#475569",
      },
      markers: { radius: 12 },
    },
  };

  // ------------------------------------------------------------------------
  // Chart Initialization
  // ------------------------------------------------------------------------
  try {
    const chart = new ApexCharts(container, options);
    chart.render();
    container._chart = chart;
    console.info(`[PriceVolumeChart] Rendered successfully on ${selector}`);
  } catch (error) {
    console.error("[PriceVolumeChart] Failed to render chart:", error);
    container.innerHTML =
      '<p class="text-sm text-rose-500 text-center py-8">Failed to load chart data.</p>';
  }
}

/**
 * ------------------------------------------------------------------------
 * Developer Notes
 * ------------------------------------------------------------------------
 * - The chart dynamically adapts to dark mode via document class detection.
 * - Tailwind palette reference:
 *     Purple 500 → #a855f7
 *     Sky 400    → #38bdf8
 *     Slate 400  → #94a3b8
 *
 * - For performance on pages with multiple charts:
 *     → Use IntersectionObserver to lazy-load when scrolled into view.
 *
 * - To theme all charts globally:
 *     Create a base file at `resources/js/tickerwolf/charts/theme.js`
 *     exporting shared Apex config fragments (colors, grid, font).
 */
