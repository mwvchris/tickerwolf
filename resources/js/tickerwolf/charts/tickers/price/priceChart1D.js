/**
 * 1D Price Chart — light series, right y-axis, ~6 x-labels.
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
      animations: { enabled: true, easing: 'easeinout', speed: 300 },
    },
    series: [{ name: 'Price', data: series }],
    dataLabels: {
      enabled: false,
    },
    xaxis: {
      type: 'datetime',
      tickAmount: 6,
      labels: {
        rotate: 0,
        datetimeUTC: false,
        datetimeFormatter: { year: 'yyyy', month: 'MMM dd', day: 'MMM dd', hour: 'MMM dd, HH:mm' },
        style: { colors: theme.fontColor, fontSize: '11px' },
      },
      axisBorder: { show: false },
      axisTicks:  { show: false },
    },
    yaxis: {
      opposite: true,
      decimalsInFloat: 2,
      labels: {
        formatter: (v) => `$${Number(v).toFixed(2)}`,
        style: { colors: theme.fontColor },
      },
    },
    stroke: { curve: 'smooth', width: 2, colors: [theme.colors.price] },
    fill: {
      type: 'gradient',
      gradient: { shadeIntensity: 0.25, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] },
    },
    grid: theme.grid,
    tooltip: {
      theme: tooltipTheme,
      x: {
        formatter: (val) => {
          // Include time for 1D when multiple points per day would exist.
          // Our data is daily, but this formatter is future-proof.
          const d = new Date(val);
          return d.toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        },
      },
      y: { formatter: (v) => `$${Number(v).toFixed(2)}` },
      shared: false,
      intersect: true,
      onDatasetHover: { highlightDataSeries: true },
    },
    markers: { size: 0 },
  };

  const chart = new ApexCharts(el, opts);
  chart.render();
  return chart;
}