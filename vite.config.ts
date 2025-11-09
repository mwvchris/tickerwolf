/**
 * --------------------------------------------------------------------------
 * Vite Config — TickerWolf.ai (Vue 3 + Inertia + Lineone)
 * --------------------------------------------------------------------------
 *  Modernized for:
 *   • Laravel 12 + Inertia + Vue 3.5
 *   • Tailwind CSS 4 (via @tailwindcss/vite)
 *   • Vite 7 (Lineone 3.2.x compatible)
 *   • Full JS architecture: app.js, main.js, components, services, utils
 *   • Public assets (fonts/images) from /public/
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
    // -----------------------------------------------------------------------
    // Laravel + Inertia integration
    // -----------------------------------------------------------------------
    laravel({
      input: [
        // --- Core entries ---
        'resources/js/app.ts',        // Inertia SPA entry
        'resources/js/blade-app.js',  // Blade-only entry

        // --- Lineone global initializers ---
        'resources/js/lineone/app.js',
        'resources/js/lineone/main.js',

        // --- Auto-include all Lineone modular JS files ---
        ...globSync('resources/js/lineone/pages/**/*.js'),
        ...globSync('resources/js/lineone/libs/**/*.js'),

        // --- Include Lineone theme CSS root ---
        'resources/css/lineone/app.css',
      ],
      ssr: 'resources/js/ssr.ts',
      refresh: true,
    }),

    // -----------------------------------------------------------------------
    // Vue 3 single-file component (SFC) support
    // -----------------------------------------------------------------------
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
    }),

    // -----------------------------------------------------------------------
    // Tailwind CSS 4 integration
    // -----------------------------------------------------------------------
    tailwindcss(),
  ],

  // -------------------------------------------------------------------------
  // Resolve Aliases
  // -------------------------------------------------------------------------
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

  // -------------------------------------------------------------------------
  // Build Output Configuration
  // -------------------------------------------------------------------------
  build: {
    outDir: 'public/build',
    assetsDir: 'assets',
    manifest: true,
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: ({ name }) => {
          if (/\.(css)$/.test(name ?? '')) return 'css/[name]-[hash][extname]'
          if (/\.(png|jpe?g|gif|svg|ico)$/.test(name ?? ''))
            return 'images/[name]-[hash][extname]'
          if (/\.(woff2?|eot|ttf|otf)$/.test(name ?? ''))
            return 'fonts/[name]-[hash][extname]'
          return 'assets/[name]-[hash][extname]'
        },
      },
    },
  },

  // -------------------------------------------------------------------------
  // Dev Server Configuration (Docker-aware)
  // -------------------------------------------------------------------------
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    watch: {
      usePolling: true, // important for Docker bind mounts
    },
    hmr: {
      host: 'localhost',
    },
  },
})