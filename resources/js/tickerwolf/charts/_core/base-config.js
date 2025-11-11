/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — Base ApexCharts Configuration
 * --------------------------------------------------------------------------
 * Serves as the foundation for all chart instances.  Individual chart modules
 * should spread (...baseChartConfig) into their own config object, overriding
 * only what’s necessary for their chart type.
 * --------------------------------------------------------------------------
 */

import { chartTheme } from "./theme";

export const baseChartConfig = {
  chart: {
    toolbar: { show: false }, // Hides the default Apex toolbar
    animations: { enabled: true, easing: "easeinout", speed: 600 },
    parentHeightOffset: 0,
    foreColor: chartTheme.fontColor,
    fontFamily: chartTheme.fontFamily,
  },
  grid: {
    show: true,
    borderColor: chartTheme.grid.borderColor,
    strokeDashArray: chartTheme.grid.strokeDashArray,
  },
  legend: {
    show: false,
    fontFamily: chartTheme.fontFamily,
    labels: { colors: chartTheme.fontColor },
  },
  dataLabels: { enabled: false },
  stroke: {
    show: true,
    width: 2,
    curve: "smooth",
    colors: ["transparent"],
  },
  tooltip: {
    theme: "dark",
    x: { show: true },
    y: { formatter: val => (val !== null ? val.toLocaleString() : "—") },
  },
};