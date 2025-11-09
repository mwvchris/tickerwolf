/**
 * --------------------------------------------------------------------------
 * Tailwind Config — TickerWolf.ai (Laravel 12 + Vue 3 + Lineone)
 * --------------------------------------------------------------------------
 * - Fully compatible with TailwindCSS 4.1.x and @tailwindcss/vite.
 * - Keeps the default color palette (fixes "unknown utility bg-slate-50").
 * - Supports dark mode via class.
 * - Scans all Blade, Vue, TS, and CSS files under /resources.
 * --------------------------------------------------------------------------
 */

import { defineConfig } from 'tailwindcss'

export default defineConfig({
  darkMode: 'class',

  content: [
    './resources/views/**/*.blade.php',
    './resources/**/*.vue',
    './resources/**/*.js',
    './resources/**/*.ts',
    './resources/css/lineone/**/*.css',
  ],

  theme: {
    extend: {
      fontFamily: {
        sans: [
          'var(--font-sans)',
          'ui-sans-serif',
          'system-ui',
          'sans-serif',
          'Apple Color Emoji',
          'Segoe UI Emoji',
          'Segoe UI Symbol',
          'Noto Color Emoji',
        ],
        inter: ['var(--font-inter)', 'sans-serif'],
      },

      boxShadow: {
        soft: '0 3px 10px 0 rgb(48 46 56 / 6%)',
        'soft-dark': '0 3px 10px 0 rgb(25 33 50 / 30%)',
      },
    },
  },

  plugins: [],
})
