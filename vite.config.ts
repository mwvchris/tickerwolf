/**
 * --------------------------------------------------------------------------
 * Vite Config — TickerWolf.ai (Laravel 12 + Inertia + Vue 3 + Lineone)
 * --------------------------------------------------------------------------
 * Clean Tailwind 4.1.x + Lineone 3.2.1 integration.
 * Ensures single Tailwind runtime, correct entry order, and HMR stability.
 * --------------------------------------------------------------------------
 */

import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'
import { globSync } from 'glob'

const devServerUrl = process.env.VITE_DEV_SERVER_URL ?? 'http://tickerwolf.test:5173'
const parsedDevUrl = new URL(devServerUrl)
const devHost = parsedDevUrl.hostname || 'tickerwolf.test'
const devPort = Number(parsedDevUrl.port || 5173)
const devProtocol = parsedDevUrl.protocol.replace(':', '') || 'http'
const hmrProtocol = devProtocol === 'https' ? 'wss' : 'ws'

export default defineConfig({
  plugins: [
    // Laravel + Inertia entrypoints
    laravel({
      input: [
        // Inertia app
        'resources/js/app.ts',
        // Blade-only JS
        'resources/js/blade-app.js',

        // Lineone theme entry
        'resources/css/lineone/app.css',
        'resources/js/lineone/app.js',
        'resources/js/lineone/main.js',

        // Optional dynamic Lineone modules
        ...globSync('resources/js/lineone/pages/**/*.js'),
        ...globSync('resources/js/lineone/libs/**/*.js'),
      ],
      refresh: true,
    }),

    // Vue support
    vue({
      template: { transformAssetUrls: { base: null, includeAbsolute: false } },
    }),

    // ✅ Single Tailwind 4 runtime (no duplication via PostCSS)
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
    port: devPort,
    strictPort: true,
    cors: {
      origin: true, // mirror requesting origin (tickerwolf.test) so module scripts/fonts pass CORS checks
    },
    watch: {
      usePolling: true,
      interval: 150,
    },
    hmr: {
      host: devHost,
      port: devPort,
      clientPort: devPort,
      protocol: hmrProtocol,
    },
  },
})
