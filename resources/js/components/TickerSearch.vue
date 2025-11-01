<template>
  <div class="relative">
    <input v-model="q" @input="onInput" @keydown.enter.prevent="go" class="border rounded px-3 py-2 w-full" placeholder="Search tickers..." />
    <ul v-if="suggestions.length" class="absolute bg-white border rounded mt-1 w-full z-50 max-h-64 overflow-auto">
      <li v-for="item in suggestions" :key="item.ticker" class="px-3 py-2 hover:bg-gray-100 cursor-pointer" @click="goTo(item)">
        <div class="text-sm font-medium">{{ item.ticker }} <span class="text-gray-500">—</span> <span class="text-xs text-gray-600">{{ item.name }}</span></div>
      </li>
    </ul>
  </div>
</template>

<script>
import axios from 'axios';
export default {
  data() {
    return {
      q: '',
      suggestions: [],
      timer: null,
    };
  },
  methods: {
    onInput() {
      clearTimeout(this.timer);
      if (!this.q) {
        this.suggestions = [];
        return;
      }
      this.timer = setTimeout(this.fetchSuggestions, 250);
    },
    async fetchSuggestions() {
      try {
        const res = await axios.get('/api/tickers/search', { params: { q: this.q }});
        this.suggestions = res.data;
      } catch (e) {
        this.suggestions = [];
      }
    },
    goTo(item) {
      // navigate to the canonical ticker page
      const slug = item.slug || item.name && item.name.toLowerCase().replace(/\s+/g, '-');
      window.location.href = `/tickers/${item.ticker}/${slug}`;
    },
    go() {
      if (this.suggestions.length) {
        this.goTo(this.suggestions[0]);
      } else {
        // fallback: go to search with ?q=
        window.location.href = `/search?q=${encodeURIComponent(this.q)}`;
      }
    }
  }
}
</script>
