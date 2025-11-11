/**
 * --------------------------------------------------------------------------
 * TickerWolf.ai — Chart Theme Configuration
 * --------------------------------------------------------------------------
 * Centralizes all color, typography, and styling conventions for charts
 * rendered across the TickerWolf platform. This file allows us to maintain
 * visual consistency between modules (ApexCharts, D3, etc.) while making it
 * trivial to adjust theme colors or fonts globally.
 * --------------------------------------------------------------------------
 */

/**
 * Helper function to merge base theme with overrides for specific contexts.
 * This allows us to create variants like light and dark themes by extending
 * the core theme configuration without duplicating common properties.
 */
function mergeThemes(base, overrides) {
  return {
    ...base,
    ...overrides,
    colors: { ...base.colors, ...overrides.colors },
  };
}

/**
 * Helper function to select a color based on a given theme context.
 * Useful for dynamically picking colors for series or UI elements depending
 * on light/dark mode or semantic usage.
 */
function selectColor(theme, key) {
  return theme.colors[key] || theme.colors.neutral;
}

export const chartThemeBase = {
  /**
   * Core Color Palette
   * Mirrors TickerWolf’s brand and Tailwind utility palette.
   * Each key should represent a logical semantic use, not a literal color name.
   */
  colors: {
    price: "#4C4EE7",               // Primary purple for closing prices
    volume: "#0EA5E9",              // Light blue for volume bars
    rsi: "#10B981",                 // Green for RSI indicators
    macd: "#F59E0B",                // Amber for MACD signals
    sentimentPositive: "#22C55E",  // Bright green for bullish sentiment
    sentimentNegative: "#EF4444",  // Red for bearish sentiment
    neutral: "#94A3B8",             // Muted gray for baselines
  },

  /**
   * Typography Defaults
   * Standard font family and base font color for all charts.
   */
  typography: {
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif",
    fontColor: "#94A3B8",
    fontWeight: 400,
  },

  /**
   * Grid Styling Defaults
   * Defines border color and stroke style for chart grids.
   */
  grid: {
    borderColor: "#1E293B",
    strokeDashArray: 3,
  },

  /**
   * Series Defaults
   * Default styles applied to all data series unless overridden.
   */
  series: {
    strokeWidth: 2,
    strokeLinecap: "round",
    curve: "smooth",
  },

  /**
   * Tooltip Styling Defaults
   * Base styling for tooltips displayed on hover or focus.
   */
  tooltip: {
    backgroundColor: "#1E293B",
    borderColor: "#4C4EE7",
    fontColor: "#FFFFFF",
    fontSize: "0.875rem",
    borderRadius: 4,
    padding: "8px 12px",
  },

  /**
   * Axis Styling Defaults
   * Styles for x and y axes including labels, ticks and lines.
   */
  axis: {
    labelColor: "#94A3B8",
    tickColor: "#475569",
    axisLineColor: "#334155",
    fontSize: "0.75rem",
    fontWeight: 500,
  },

  /**
   * Gradient Definitions
   * Reusable gradient definitions for series fills or backgrounds.
   */
  gradients: {
    price: ["#4C4EE7", "#6366F1"],
    volume: ["#0EA5E9", "#3B82F6"],
    rsi: ["#10B981", "#34D399"],
    macd: ["#F59E0B", "#FBBF24"],
  },
};

/**
 * Light Theme Variant
 * Overrides and extends the base theme for light backgrounds.
 */
export const chartThemeLight = mergeThemes(chartThemeBase, {
  colors: {
    neutral: "#64748B",
  },
  typography: {
    fontColor: "#475569",
  },
  grid: {
    borderColor: "#E2E8F0",
    strokeDashArray: 2,
  },
  tooltip: {
    backgroundColor: "#FFFFFF",
    borderColor: "#4C4EE7",
    fontColor: "#1E293B",
  },
  axis: {
    labelColor: "#64748B",
    tickColor: "#CBD5E1",
    axisLineColor: "#E2E8F0",
  },
});

/**
 * Dark Theme Variant
 * Overrides and extends the base theme for dark backgrounds.
 */
export const chartThemeDark = mergeThemes(chartThemeBase, {
  colors: {
    neutral: "#94A3B8",
  },
  typography: {
    fontColor: "#94A3B8",
  },
  grid: {
    borderColor: "#1E293B",
    strokeDashArray: 3,
  },
  tooltip: {
    backgroundColor: "#1E293B",
    borderColor: "#4C4EE7",
    fontColor: "#FFFFFF",
  },
  axis: {
    labelColor: "#94A3B8",
    tickColor: "#475569",
    axisLineColor: "#334155",
  },
});

/**
 * Exported helper functions for external use.
 */
export const themeHelpers = {
  mergeThemes,
  selectColor,
};

/**
 * Determine the active theme based on document state (dark mode) if available.
 * Defaults to the light variant for SSR or non-browser contexts.
 */
export function resolveChartTheme(mode) {
  if (mode === "dark") return chartThemeDark;
  if (mode === "light") return chartThemeLight;

  if (typeof document !== "undefined") {
    const prefersDark = document.documentElement.classList.contains("dark");
    return prefersDark ? chartThemeDark : chartThemeLight;
  }

  return chartThemeLight;
}

export function getChartTheme() {
  return resolveChartTheme();
}

export function getThemeMode(theme = getChartTheme()) {
  return theme === chartThemeDark ? "dark" : "light";
}

export function onChartThemeChange(callback) {
  if (typeof window === "undefined") return;

  window.addEventListener("change:darkmode", (event) => {
    callback(resolveChartTheme(event?.detail?.currentMode));
  });
}

export const chartTheme = getChartTheme();
