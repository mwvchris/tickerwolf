/**
 * --------------------------------------------------------------------------
 * PostCSS Config — TickerWolf.ai
 * --------------------------------------------------------------------------
 * Tailwind 4 uses its own namespaced PostCSS bridge.
 * The order below matters: Tailwind first, Autoprefixer second.
 * --------------------------------------------------------------------------
 */

export default {
  plugins: {
    '@tailwindcss/postcss': {},
    autoprefixer: {},
  },
}
