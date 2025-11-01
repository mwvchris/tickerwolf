<template>
  <div class="max-w-7xl mx-auto px-4 py-8 space-y-8" v-if="tickerReady">
    <!-- ===== Ticker Profile & Stats Grid ===== -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      <!-- Left Column: Profile Header -->
      <div class="bg-white dark:bg-gray-900 shadow rounded-2xl p-6 flex flex-col items-center text-center">
        <div class="w-28 h-28 bg-gray-200 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4 overflow-hidden">
          <img v-if="ticker.branding_logo_url" :src="ticker.branding_logo_url" alt="logo" class="object-contain w-full h-full" />
          <span v-else class="text-gray-400 text-xl font-semibold">Logo</span>
        </div>

        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">
          {{ ticker.ticker }}
        </h1>
        <p class="text-gray-600 dark:text-gray-400 text-lg mb-2">
          {{ ticker.name }}
        </p>

        <div class="text-sm text-gray-500 dark:text-gray-400 mb-4">
          <a v-if="ticker.homepage_url" :href="ticker.homepage_url" target="_blank" rel="noopener" class="hover:underline">
            Visit company site
          </a>
        </div>

        <div class="space-y-2 text-gray-700 dark:text-gray-300 w-full">
          <div><span class="font-semibold">Market:</span> {{ ticker.market }}</div>
          <div><span class="font-semibold">Locale:</span> {{ ticker.locale }}</div>
          <div><span class="font-semibold">Primary Exchange:</span> {{ ticker.primary_exchange }}</div>
          <div><span class="font-semibold">Currency:</span> {{ ticker.currency_name }}</div>
          <div><span class="font-semibold">Type:</span> {{ ticker.type }}</div>
          <div><span class="font-semibold">Active:</span> {{ ticker.active ? 'Yes' : 'No' }}</div>
        </div>
      </div>

      <!-- Right Column: Quick Stats / Charts -->
      <div class="lg:col-span-2 bg-white dark:bg-gray-900 shadow rounded-2xl p-6 flex flex-col gap-4">
        <div class="flex items-start justify-between">
          <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-2">Quick Stats</h2>
          <div class="text-sm text-gray-500 dark:text-gray-400">Snapshot</div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div
            v-for="(stat, i) in quickStats"
            :key="i"
            class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg text-center shadow-sm"
          >
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ stat.label }}</div>
            <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ stat.value }}</div>
          </div>
        </div>

        <div class="mt-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
              <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">Company Description</h3>
              <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed whitespace-pre-line">
                {{ ticker.description || 'No description available.' }}
              </p>
            </div>

            <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
              <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">Company Details</h3>
              <ul class="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                <li><strong>Website:</strong> <a v-if="ticker.homepage_url" :href="ticker.homepage_url" class="text-blue-600 dark:text-blue-400 hover:underline" target="_blank">{{ ticker.homepage_url }}</a><span v-else>—</span></li>
                <li><strong>Phone:</strong> {{ ticker.phone_number || '—' }}</li>
                <li><strong>Employees:</strong> {{ ticker.total_employees ? ticker.total_employees.toLocaleString() : '—' }}</li>
                <li><strong>List date:</strong> {{ ticker.list_date || '—' }}</li>
                <li><strong>SIC:</strong> {{ ticker.sic_description || '—' }}</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="mt-6 h-64 bg-gray-100 dark:bg-gray-800 rounded-xl flex items-center justify-center text-gray-400 dark:text-gray-500">
          Chart Placeholder
        </div>
      </div>
    </div>

    <!-- ===== AI Analysis Section ===== -->
    <div class="bg-white dark:bg-gray-900 shadow rounded-2xl p-6 mt-8">
      <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mb-4">AI Stock Analysis</h2>

      <TickerAnalysis
        v-if="tickerReady"
        :ticker="ticker.ticker"
        :user-auth="user.authenticated"
        :login-url="user.loginUrl"
        :latest-analysis="page.props?.latestAnalysis || null"
      />
    </div>
  </div>

  <!-- Fallback while waiting for ticker -->
  <div v-else class="text-center text-gray-500 dark:text-gray-400 py-10">
    Loading ticker data…
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import TickerAnalysis from '@/components/TickerAnalysis.vue'

const page = usePage()

const ticker = computed(() => page.props?.ticker || null)
const tickerReady = computed(() => ticker.value && typeof ticker.value.ticker === 'string')

const user = computed(() => ({
  authenticated: Boolean(page.props?.user?.authenticated),
  loginUrl: page.props?.user?.loginUrl || '/login',
}))

// Use quickStats from server, fallback to empty array
const quickStats = computed(() => page.props?.quickStats || [])

// Expose the page object for child components
</script>

<style scoped>
/* small tweaks to ensure logos stay contained */
img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
</style>