<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    {{-- ============================================================
         Meta / App Configuration
    ============================================================ --}}
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta
      name="viewport"
      content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0"
    />

    <title>{{ config('app.name', 'TickerWolf.ai') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/app-logo.svg') }}" />

    {{-- ============================================================
         CSS & JS — Compiled by Vite
         ------------------------------------------------------------
         These handle:
           - Lineone template (core + components)
           - Blade-specific JS (for non-Inertia pages)
           - Any app-wide JS injected via @vite
    ============================================================ --}}
    @vite([
        'resources/css/lineone/app.css',
        'resources/js/lineone/libs/components.js',
        'resources/js/lineone/app.js',
        'resources/js/blade-app.js',
    ])

    {{-- ============================================================
         Google Fonts
    ============================================================ --}}
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />

    {{-- ============================================================
         Dark Mode — Flicker Prevention
         Keeps dark theme active before app JS initializes.
    ============================================================ --}}
    <script>
      if (localStorage.getItem('dark-mode') === 'dark') {
        document.documentElement.classList.add('dark');
      }
    </script>

    {{-- ============================================================
         Vendor-Specific Head Injections
         ------------------------------------------------------------
         Allows child templates to inject additional <script> or <link>
         tags (for chart libraries, plugins, etc.)
         Example usage:
           @push('head')
             <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
           @endpush
    ============================================================ --}}
    @stack('head')
  </head>

  {{-- ============================================================
       Body / Layout Wrapper
       ------------------------------------------------------------
       Body classes like `is-header-blur` are handled by Lineone.
       Additional classes (e.g. `is-sidebar-open`) can be toggled
       by page-level Blade templates.
  ============================================================ --}}
  <body class="is-header-blur">

    {{-- ============================================================
         App Preloader (Optional)
         ------------------------------------------------------------
         If enabled, add `cloak` class to #root below.
         This can help hide layout shifts on large dashboards.
    ============================================================ --}}
    <!--
    <div class="app-preloader fixed z-50 grid h-full w-full place-content-center bg-slate-50 dark:bg-navy-900">
      <div class="app-preloader-inner relative inline-block size-48"></div>
    </div>
    -->

    {{-- ============================================================
         Root App Container
         ------------------------------------------------------------
         Contains the entire UI shell: header, sidebars, main content.
    ============================================================ --}}
    <div id="root" class="min-h-100vh flex grow bg-slate-50 dark:bg-navy-900">

      {{-- Left Sidebar --}}
      @include('partials.left-sidebar')

      {{-- App Header --}}
      @include('partials.header')

      {{-- Mobile Searchbar --}}
      @include('partials.mobile-searchbar')

      {{-- Right Sidebar --}}
      @include('partials.right-sidebar')

      {{-- ============================================================
           Main Content Wrapper
           ------------------------------------------------------------
           This is where page-specific Blade templates inject content.
           Chart sections, analytics dashboards, etc. will all appear here.
      ============================================================ --}}
      <main class="main-content w-full pb-8">
        @yield('content')
      </main>
    </div>

    {{-- ============================================================
         Global JS Vendors (Optional CDN Section)
         ------------------------------------------------------------
         Load vendor libraries that aren't part of your Vite bundle.
         Example: ApexCharts, Chart.js, or D3 (for prototyping)
         Only load what's needed for non-Vite-compatible packages.
    ============================================================ --}}
    {{-- ApexCharts (safe via CDN; local build handled by Vite if preferred) --}}
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    {{-- ============================================================
         Global Initialization Hooks
         ------------------------------------------------------------
         This script runs AFTER Vite + vendor scripts and BEFORE
         page-specific charts are loaded.  Use it for global event
         listeners, theme binding, or analytics setup.
    ============================================================ --}}
    <script>
      document.addEventListener("DOMContentLoaded", () => {
        // Global utilities available across chart pages
        window.TickerWolf = window.TickerWolf || {};
        window.TickerWolf.env = {
          appUrl: "{{ config('app.url') }}",
          csrfToken: "{{ csrf_token() }}",
        };

        console.debug("[TickerWolf] Layout initialized");
      });
    </script>

    {{-- ============================================================
         Vendor-Specific Scripts (optional, stacked from child views)
         ------------------------------------------------------------
         Example (in show.blade.php):
           @push('vendor-scripts')
             <script src="https://cdn.jsdelivr.net/npm/moment"></script>
           @endpush
    ============================================================ --}}
    @stack('vendor-scripts')

    {{-- ============================================================
         Page-Specific Scripts (Chart Renderers, etc.)
         ------------------------------------------------------------
         This is where your per-page modules like:
           renderPriceVolumeChart(),
           renderRsiChart(),
           renderFundamentalsChart(),
         get injected from @push('scripts') blocks in Blade files.
    ============================================================ --}}
    @stack('scripts')

    {{-- ============================================================
         Inline Script Stack (Rarely Used)
         ------------------------------------------------------------
         Use only for one-off inline JS logic that cannot be modularized.
         Keeps chart modules and page code organized separately.
    ============================================================ --}}
    @stack('inline-scripts')

  </body>
</html>