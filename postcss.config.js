/**
 * PostCSS Config — TickerWolf.ai
 * --------------------------------------------------------------
 * Only autoprefixer needed; Tailwind is handled by Vite plugin.
 * --------------------------------------------------------------
 */
import autoprefixer from 'autoprefixer'

export default {
  plugins: [autoprefixer()],
}