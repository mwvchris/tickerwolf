<template>
  <div class="relative w-full">
    <input
      type="text"
      placeholder="Type ticker..."
      v-model="query"
      @input="searchTickers"
      class="w-full border rounded p-2"
    />
    <ul v-if="results.length" class="absolute bg-white border mt-1 rounded shadow-lg w-full z-10 max-h-64 overflow-auto">
      <li
        v-for="ticker in results"
        :key="ticker.id"
        class="p-2 hover:bg-gray-100 cursor-pointer"
        @click="selectTicker(ticker)"
      >
        {{ ticker.ticker }} - {{ ticker.name }}
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';

const query = ref('');
const results = ref([]);

const searchTickers = async () => {
  if (query.value.length < 2) {
    results.value = [];
    return;
  }

  try {
    const { data } = await axios.get(`/api/tickers/search?q=${encodeURIComponent(query.value)}`);
    results.value = data;
  } catch (err) {
    console.error('API error:', err);
    results.value = [];
  }
};

const selectTicker = (ticker) => {
  window.location.href = `/tickers/${ticker.ticker}`;
};
</script>
