/**
 * --------------------------------------------------------------------------
 * PostCSS Config — TickerWolf.ai
 * --------------------------------------------------------------------------
 * Tailwind 4 uses its own namespaced PostCSS bridge.
 * The order below matters: Tailwind first, Autoprefixer second.
 * --------------------------------------------------------------------------
 */

import tailwindcss from '@tailwindcss/postcss'
import autoprefixer from 'autoprefixer'

export default {
  plugins: [tailwindcss(), autoprefixer()],
}
