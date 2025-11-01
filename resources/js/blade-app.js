import { createApp, h } from 'vue';
import AutocompleteSearch from './components/AutocompleteSearch.vue';
import TickerAnalysis from './components/TickerAnalysis.vue';

// Mount AutocompleteSearch if element exists
const searchEl = document.getElementById('ticker-search');
if (searchEl) {
    createApp({ render: () => h(AutocompleteSearch) }).mount(searchEl);
}

// Mount TickerAnalysis if element exists
const analysisEl = document.getElementById('ticker-analysis-app');
if (analysisEl) {
    const ticker = analysisEl.dataset.ticker;
    const userAuth = analysisEl.dataset.userAuth === 'true';
    const loginUrl = analysisEl.dataset.loginUrl;

    createApp({
        render: () => h(TickerAnalysis, { ticker, userAuth, loginUrl })
    }).mount(analysisEl);
}