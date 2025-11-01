<template>
  <div class="space-y-4">
    <!-- ===== Guest View ===== -->
    <div
      v-if="!userAuth"
      class="border border-blue-300 bg-blue-50 text-blue-800 rounded-lg p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between"
    >
      <div class="text-left mb-2 sm:mb-0">
        <p class="font-medium">You must be logged in to generate AI analyses.</p>
      </div>

      <div class="flex items-center gap-3">
        <a
          :href="loginUrl"
          class="inline-block bg-blue-600 text-white text-sm font-semibold px-4 py-2 rounded-md hover:bg-blue-700 transition-colors"
        >
          Log in
        </a>
        <a href="/register" class="text-blue-700 text-sm font-medium hover:underline">
          Register
        </a>
      </div>
    </div>

    <!-- ===== Authenticated View ===== -->
    <div v-else class="space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2">
          <label for="llm-provider" class="text-sm font-medium text-gray-700 dark:text-gray-200">
            Select AI Provider:
          </label>

          <select
            id="llm-provider"
            v-model="selectedProvider"
            class="border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200 focus:ring-opacity-50 text-sm dark:bg-gray-800 dark:text-gray-100"
          >
            <option disabled value="">Choose a provider</option>
            <option v-for="provider in llmProviders" :key="provider.id" :value="provider.id">
              {{ provider.name }}
            </option>
          </select>
        </div>

        <button
          @click="generateAnalysis"
          :disabled="loading || !selectedProvider"
          class="bg-blue-600 text-white text-sm font-semibold px-5 py-2 rounded-md hover:bg-blue-700 disabled:opacity-50 transition-colors"
        >
          <span v-if="loading">Generating...</span>
          <span v-else>Generate Analysis</span>
        </button>
      </div>

      <!-- ===== Error or Info Message ===== -->
      <div v-if="error" class="text-red-600 text-sm font-medium">
        {{ error }}
      </div>

      <!-- ===== Analysis Output ===== -->
      <div
        v-if="analysis && analysis.trim().length > 0"
        class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-gray-50 dark:bg-gray-900"
      >
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">
          {{ currentProviderName }} Analysis
        </h3>
        <p class="text-gray-700 dark:text-gray-200 whitespace-pre-line leading-relaxed">
          {{ analysis }}
        </p>

        <p v-if="latestCompletedAt" class="text-xs text-gray-500 mt-2">
          Last updated: {{ latestCompletedAt }}
        </p>
      </div>

      <!-- ===== Empty State ===== -->
      <div v-else-if="!loading" class="text-gray-500 dark:text-gray-400 italic">
        No analysis generated yet. Select a provider and click “Generate Analysis.”
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { ensureCsrfCookie } from '@/auth'

const props = defineProps<{
  ticker: string
  userAuth: boolean
  loginUrl: string
  latestAnalysis?: {
    provider?: string
    content?: string
    completed?: string
  } | null
}>()

// ====== STATE ======
const llmProviders = ref<{ id: string; name: string }[]>([])
const selectedProvider = ref('')
const loading = ref(false)
const analysis = ref('')
const error = ref('')
const latestCompletedAt = ref<string | null>(null)
const providerFromServer = ref('')

// ====== INITIALIZE FROM PROPS ======
if (props.latestAnalysis && props.latestAnalysis.content) {
  analysis.value = props.latestAnalysis.content
  providerFromServer.value = props.latestAnalysis.provider || ''
  latestCompletedAt.value = props.latestAnalysis.completed
}

// ====== HELPERS ======
const getXsrfToken = () => {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

const displayProviderName = (id: string) => {
  const match = llmProviders.value.find((p) => p.id === id)
  return match ? match.name : id
}

// Dynamically determine which provider name to display
const currentProviderName = computed(() => {
  if (selectedProvider.value) return displayProviderName(selectedProvider.value)
  if (providerFromServer.value) return displayProviderName(providerFromServer.value)
  return 'AI'
})

// ====== FETCH PROVIDERS ======
const fetchProviders = async () => {
  try {
    await ensureCsrfCookie()
    const res = await fetch('/api/ai/providers', {
      method: 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
    })
    if (!res.ok) throw new Error('Failed to load providers')
    const data = await res.json()
    llmProviders.value = data.providers
  } catch (err: any) {
    console.error(err)
    error.value = 'Error fetching AI provider list.'
  }
}

// ====== GENERATE ANALYSIS ======
const generateAnalysis = async () => {
  if (!selectedProvider.value) return
  loading.value = true
  error.value = ''
  analysis.value = ''

  try {
    await ensureCsrfCookie()

    const response = await fetch('/api/ai/analysis', {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': getXsrfToken(),
      },
      body: JSON.stringify({
        ticker: props.ticker,
        provider: selectedProvider.value,
      }),
    })

    const text = await response.text()
    let data
    try {
      data = JSON.parse(text)
    } catch {
      throw new Error(`Unexpected response: ${text.substring(0, 200)}`)
    }

    if (!response.ok) {
      throw new Error(data.message || 'Request failed.')
    }

    analysis.value =
      data.analysis ||
      data.structured?.summary ||
      'Analysis request submitted successfully. Please wait for results.'

    latestCompletedAt.value = new Date().toLocaleString()
    providerFromServer.value = selectedProvider.value
  } catch (err: any) {
    console.error(err)
    error.value = err.message || 'An error occurred while generating analysis.'
  } finally {
    loading.value = false
  }
}

// ====== ON MOUNT ======
onMounted(() => {
  fetchProviders()
})
</script>
