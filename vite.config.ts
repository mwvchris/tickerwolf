/**
 * --------------------------------------------------------------------------
 * Vite Config — TickerWolf.ai (Laravel 12 + Inertia + Vue 3 + Lineone)
 * --------------------------------------------------------------------------
 * Mirrors Lineone-Laravel 3.2.1 baseline while supporting Inertia/Vue.
 * --------------------------------------------------------------------------
 */

import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'
import { globSync } from 'glob'

export default defineConfig({
  plugins: [
    // Laravel / Inertia integration
    laravel({
      input: [
        // Core entries
        'resources/css/app.css',
        'resources/js/app.ts',
        'resources/js/blade-app.js',

        // Lineone assets
        'resources/css/lineone/app.css',
        'resources/js/lineone/app.js',
        'resources/js/lineone/main.js',

        // Optional JS modules
        ...globSync('resources/js/lineone/pages/**/*.js'),
        ...globSync('resources/js/lineone/libs/**/*.js'),
      ],
      refresh: true,
    }),

    // Vue 3 SFC support
    vue({
      template: { transformAssetUrls: { base: null, includeAbsolute: false } },
    }),

    // Single Tailwind runtime (do NOT duplicate in PostCSS)
    tailwindcss(),
  ],

  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'resources/js'),
      '@lineone': path.resolve(__dirname, 'resources/js/lineone'),
      '@css': path.resolve(__dirname, 'resources/css'),
      '@components': path.resolve(__dirname, 'resources/js/lineone/components'),
      '@services': path.resolve(__dirname, 'resources/js/lineone/services'),
      '@utils': path.resolve(__dirname, 'resources/js/lineone/utils'),
      '@libs': path.resolve(__dirname, 'resources/js/lineone/libs'),
      '@pages': path.resolve(__dirname, 'resources/js/lineone/pages'),
    },
  },

  build: {
    outDir: 'public/build',
    assetsDir: 'assets',
    manifest: true,
    emptyOutDir: true,
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    watch: { usePolling: true },
    hmr: { host: 'tickerwolf.test' },
  },
})