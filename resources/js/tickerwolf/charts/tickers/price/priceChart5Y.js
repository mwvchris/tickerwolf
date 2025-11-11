/**
 * 5Y Price Chart — controller down-samples to every 7 days. Right y-axis,
 * ~6 x-labels, date-only tooltip with year.
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
        datetimeFormatter: { month: 'MMM', year: 'yyyy' },
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
      x: { formatter: (val) => new Date(val).toLocaleDateString(undefined, { month: 'short', year: 'numeric' }) },
      y: { formatter: (v) => `$${Number(v).toFixed(2)}` },
    },
    markers: { size: 0 },
  };

  const chart = new ApexCharts(el, opts);
  chart.render();
  return chart;
}