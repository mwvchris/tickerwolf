/**
 * --------------------------------------------------------------------------
 *  TickerWolf.ai — Ticker Show Page Controller
 * --------------------------------------------------------------------------
 *  This page-specific script handles:
 *   - Inline dashboard widgets (Sales, Bandwidth, etc.)
 *   - Dropdown menus (Popper)
 *   - Chart rendering (ApexCharts)
 *
 *  Exported function:
 *    init() → safe entry point called from Blade once the DOM is ready.
 * --------------------------------------------------------------------------
 */

// Dependencies are assumed to be globally available (ApexCharts, Popper, Tab).
// If you move them to ESM imports later, just import at the top accordingly.

export function init() {
  console.group('[TickerWolf] Initializing show.js');

  try {
    // ----------------------------------------------------------------------
    //  Main Price Chart Tabs
    // ----------------------------------------------------------------------
    const mainPriceChartTabs = document.querySelector('#price-chart-tabs');

    if (mainPriceChartTabs) {
    const buttons = mainPriceChartTabs.querySelectorAll('button[data-chart-range]');
    if (!buttons.length) return;

    // Utility: apply active/default classes
    const setActiveTab = (activeBtn) => {
        buttons.forEach((btn) => {
        const activeClasses = btn.dataset.activeClass?.split(' ') || [];
        const defaultClasses = btn.dataset.defaultClass?.split(' ') || [];

        if (btn === activeBtn) {
            btn.classList.remove(...defaultClasses);
            btn.classList.add(...activeClasses);
        } else {
            btn.classList.remove(...activeClasses);
            btn.classList.add(...defaultClasses);
        }
        });
    };

    // Default: activate the first tab (e.g. 1M)
    const defaultActive = mainPriceChartTabs.querySelector('[data-chart-range="1M"]') || buttons[0];
    setActiveTab(defaultActive);

    // Click handling
    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
        setActiveTab(btn);

        // Optional: trigger custom event for chart updates
        const range = btn.dataset.chartRange;
        mainPriceChartTabs.dispatchEvent(
            new CustomEvent('chart:range-change', { detail: range })
        );

        console.debug(`📊 Switched to ${range} chart`);
        });
    });

    console.debug('✔ Main Price Chart Tabs initialized');
    }

    // ----------------------------------------------------------------------
    //  Sales Month Chart
    // ----------------------------------------------------------------------
    const salesChartEl = document.querySelector('#salesMonthChart');
    if (salesChartEl) {
      const salesChartConfig = {
        colors: ['#4467EF'],
        chart: {
          height: 60,
          type: 'line',
          parentHeightOffset: 0,
          toolbar: { show: false },
        },
        series: [
          {
            name: 'Sales',
            data: [654, 820, 102, 540, 154, 614],
          },
        ],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 3 },
        grid: {
          padding: { left: 0, right: 0, top: -20, bottom: -10 },
        },
        xaxis: { labels: { show: false }, axisTicks: { show: false }, axisBorder: { show: false } },
        yaxis: { labels: { show: false }, axisTicks: { show: false }, axisBorder: { show: false } },
      };

      setTimeout(() => {
        salesChartEl._chart = new ApexCharts(salesChartEl, salesChartConfig);
        salesChartEl._chart.render();
        console.debug('✔ Sales Month Chart rendered');
      });
    }

    // ----------------------------------------------------------------------
    //  Sales Overview Chart
    // ----------------------------------------------------------------------
    const salesOverviewEl = document.querySelector('#salesOverview');
    if (salesOverviewEl) {
      const salesOverviewConfig = {
        colors: ['#4C4EE7', '#0EA5E9'],
        series: [
          { name: 'Sales', data: [28, 45, 35, 50, 32, 55, 23, 60, 28, 45, 35, 50] },
          { name: 'Profit', data: [14, 25, 20, 25, 12, 20, 15, 20, 14, 25, 20, 25] },
        ],
        chart: {
          height: 255,
          type: 'bar',
          parentHeightOffset: 0,
          toolbar: { show: false },
        },
        dataLabels: { enabled: false },
        plotOptions: {
          bar: { borderRadius: 4, barHeight: '90%', columnWidth: '35%' },
        },
        legend: { show: false },
        xaxis: {
          categories: [
            'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
          ],
          axisBorder: { show: false },
          axisTicks: { show: false },
          tooltip: { enabled: false },
        },
        grid: { padding: { left: 0, right: 0, top: 0, bottom: -10 } },
        yaxis: { show: false },
        responsive: [
          {
            breakpoint: 850,
            options: {
              plotOptions: { bar: { columnWidth: '55%' } },
            },
          },
        ],
      };

      setTimeout(() => {
        salesOverviewEl._chart = new ApexCharts(salesOverviewEl, salesOverviewConfig);
        salesOverviewEl._chart.render();
        console.debug('✔ Sales Overview Chart rendered');
      });
    }

    // ----------------------------------------------------------------------
    //  Bandwidth Report Chart
    // ----------------------------------------------------------------------
    const bandwidthEl = document.querySelector('#bandwidth-chart');
    if (bandwidthEl) {
      const bandwidthConfig = {
        colors: ['#4467EF'],
        series: [
          {
            name: 'Traffic',
            data: [
              8107.85, 8128.0, 8122.9, 8165.5, 8340.7, 8423.7, 8423.5, 8514.3,
              8481.85, 8487.7, 8506.9, 8626.2, 8668.95, 8602.3, 8607.55, 8512.9,
              8496.25, 8600.65, 8881.1, 9340.85,
            ],
          },
        ],
        chart: {
          type: 'area',
          height: 220,
          parentHeightOffset: 0,
          toolbar: { show: false },
          zoom: { enabled: false },
        },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        grid: { padding: { left: 0, right: 0, top: -28, bottom: -15 } },
        tooltip: { shared: true },
        legend: { show: false },
        yaxis: { show: false },
        xaxis: {
          labels: { show: false },
          axisTicks: { show: false },
          axisBorder: { show: false },
        },
      };

      setTimeout(() => {
        bandwidthEl._chart = new ApexCharts(bandwidthEl, bandwidthConfig);
        bandwidthEl._chart.render();
        console.debug('✔ Bandwidth Chart rendered');
      });
    }

    // ----------------------------------------------------------------------
    //  Dropdown Menu Configuration
    // ----------------------------------------------------------------------
    const dropdownConfig = {
      placement: 'bottom-end',
      modifiers: [{ name: 'offset', options: { offset: [0, 4] } }],
    };

    // Helper for safe Popper init
    const safePopper = (trigger, ref, root) => {
      try {
        new Popper(trigger, ref, root, dropdownConfig);
        console.debug(`✔ Popper initialized for ${trigger}`);
      } catch (e) {
        console.warn(`[TickerWolf] Popper init failed for ${trigger}`, e);
      }
    };

    safePopper('#project-status-menu', '.popper-ref', '.popper-root');
    safePopper('#satisfaction-menu', '.popper-ref', '.popper-root');
    safePopper('#bandwidth-menu', '.popper-ref', '.popper-root');
    safePopper('#users-activity-menu', '.popper-ref', '.popper-root');

    console.groupEnd();
  } catch (err) {
    console.error('[TickerWolf] show.js initialization error:', err);
    console.groupEnd();
  }
}

/**
 * Optional: Auto-mount when global "app:mounted" event is dispatched.
 * This allows compatibility with layouts or Inertia transitions that
 * manually emit `window.dispatchEvent(new Event('app:mounted'))`.
 */
window.addEventListener('app:mounted', init, { once: true });