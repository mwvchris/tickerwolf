/**
 * --------------------------------------------------------------------------
 * chart-styles.js — Unified ApexCharts Helper for TickerWolf.ai
 * --------------------------------------------------------------------------
 * This module consolidates all chart configuration defaults across
 * priceChart1D.js, priceChart1W.js, priceChart1M.js, etc.
 *
 * Centralized responsibilities:
 *  - Shared chart options (fill, stroke, grid, font)
 *  - Safe date parsing for tooltip labels
 *  - Unified tooltip theme and style
 *  - Formatting helpers for currency and date display
 *
 * All charts should import { buildChartOptions, safeDate } from this file.
 * --------------------------------------------------------------------------
 */

import { getChartTheme, getThemeMode } from './theme.js'
import { number as fmtNumber } from './formatters.js'
import { currencyFormatter } from './utils.js'

// ---------------------------------------------------------------------------
// 🧩 Utility — Safe date parsing for timestamps or strings
// ---------------------------------------------------------------------------
export function safeDate(val) {
  if (val == null) return null
  if (typeof val === 'number') return new Date(val)
  if (typeof val === 'string') return new Date(val.includes('T') ? val : `${val}T00:00:00`)
  if (val instanceof Date) return val
  return null
}

// ---------------------------------------------------------------------------
// 🎨 Default Chart Config Template
// ---------------------------------------------------------------------------
export function buildChartOptions({
  selector = null,
  series = [],
  timeframe = '1M',
  tickAmount = 6,
  tooltipFormat = { month: 'short', day: '2-digit' },
  currency = 'USD',
  extra = {},
} = {}) {
  const theme = getChartTheme()
  const tooltipTheme = getThemeMode(theme)

  const opts = {
    chart: {
      type: 'area',
      height: 260,
      toolbar: { show: false },
      foreColor: theme.fontColor,
      fontFamily: theme.fontFamily,
      animations: { enabled: true, easing: 'easeinout', speed: 400 },
    },

    series,
    dataLabels: { enabled: false },
    markers: { size: 0 },

    stroke: {
      curve: 'smooth',
      width: 2.5,
      lineCap: 'round',
      colors: [theme.colors.price],
    },

    fill: {
      type: 'gradient',
      gradient: {
        shadeIntensity: 0.25,
        opacityFrom: 0.35,
        opacityTo: 0.05,
        stops: [0, 60, 100],
      },
    },

    grid: theme.grid,

    yaxis: {
      opposite: true,
      labels: {
        formatter: (v) =>
          fmtNumber.price ? fmtNumber.price(v, currency) : currencyFormatter(v),
        style: {
          colors: theme.fontColor,
          fontSize: '11px',
          fontFamily: theme.fontFamily,
        },
      },
    },

    xaxis: {
      type: 'datetime',
      tickAmount,
      tooltip: { enabled: false },
      labels: {
        datetimeUTC: false,
        rotate: 0,
        style: {
          colors: theme.fontColor,
          fontSize: '11px',
          fontFamily: theme.fontFamily,
        },
        formatter: (val) => {
          const d = safeDate(val)
          return d ? d.toLocaleDateString(undefined, tooltipFormat) : ''
        },
      },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },

    tooltip: {
      theme: tooltipTheme,
      style: {
        fontFamily: theme.fontFamily || '"Inter", "DM Sans", sans-serif',
        fontSize: '12px',
        fontWeight: 500,
      },
      shared: false,
      intersect: false,
      marker: { show: false },
      x: {
        show: true,
        formatter: (val) => {
          const d = safeDate(val)
          return d ? d.toLocaleDateString(undefined, tooltipFormat) : ''
        },
      },
      y: {
        formatter: (v) =>
          fmtNumber.price ? fmtNumber.price(v, currency) : currencyFormatter(v),
        title: { formatter: () => '' },
      },
    },

    legend: { show: false },

    // Allow override via `extra` (merge style)
    ...extra,
  }

  return opts
}

// ---------------------------------------------------------------------------
// ⚡ Quick Example Usage
// ---------------------------------------------------------------------------
/*
import ApexCharts from 'apexcharts'
import { buildChartOptions } from '../_core/chart-styles.js'

export function renderPriceChart(selector, series) {
  const el = document.querySelector(selector)
  if (!el || !series?.length) return null

  const opts = buildChartOptions({
    series: [{ name: 'Price', data: series }],
    timeframe: '6M',
    tickAmount: 6,
    tooltipFormat: { month: 'short', day: '2-digit' },
  })

  const chart = new ApexCharts(el, opts)
  chart.render()
  return chart
}
*/
// ---------------------------------------------------------------------------