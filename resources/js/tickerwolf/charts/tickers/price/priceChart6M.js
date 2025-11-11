/**
 * 6M Price Chart — ~6 x-labels, right y-axis.
 */
import ApexCharts from 'apexcharts';
import { getChartTheme, getThemeMode } from '../../_core/theme.js';

export function renderPriceChart(selector, series = []) {
  const el = document.querySelector(selector);
  if (!el || !series.length) return null;

  const theme = getChartTheme();
  const tooltipTheme = getThemeMode(theme);

  const opts = {
    chart: {
      type: 'area',
      height: 255,
      toolbar: { show: false },
      foreColor: theme.fontColor,
    },
    series: [{ name: 'Price', data: series }],
    dataLabels: {
      enabled: false,
    },
    xaxis: {
      type: 'datetime',
      tickAmount: 6,
      labels: {
        datetimeUTC: false,
        datetimeFormatter: { month: 'MMM', day: 'MMM dd' },
        style: { colors: theme.fontColor, fontSize: '11px' },
      },
      axisBorder: { show: false },
      axisTicks:  { show: false },
    },
    yaxis: {
      opposite: true,
      labels: { formatter: (v) => `$${Number(v).toFixed(2)}`, style: { colors: theme.fontColor } },
    },
    stroke: { curve: 'smooth', width: 2, colors: [theme.colors.price] },
    fill: { type: 'gradient', gradient: { shadeIntensity: 0.25, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] } },
    grid: theme.grid,
    tooltip: {
      theme: tooltipTheme,
      x: { formatter: (val) => new Date(val).toLocaleDateString(undefined, { month: 'short', day: '2-digit' }) },
      y: { formatter: (v) => `$${Number(v).toFixed(2)}` },
    },
    markers: { size: 0 },
  };

  const chart = new ApexCharts(el, opts);
  chart.render();
  return chart;
}