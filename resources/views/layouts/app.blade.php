<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <!-- ============================================================
         Meta / App Configuration
    ============================================================ -->
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta
      name="viewport"
      content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0"
    />

    <title>{{ config('app.name', 'TickerWolf.ai') }} — Blurred Header</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/app-logo.svg') }}" />

    <!-- ============================================================
         CSS & JS — Compiled by Vite
         These handle all application styling and scripts.
    ============================================================ -->
    @vite([
        'resources/css/lineone/app.css',
        'resources/js/lineone/app.js',
        'resources/js/blade-app.js',
    ])

    <!-- ============================================================
         Google Fonts
    ============================================================ -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet" />

    <!-- ============================================================
         Dark Mode — Flicker Prevention
         Keeps dark theme before app JS initializes.
    ============================================================ -->
    <script>
      if (localStorage.getItem('dark-mode') === 'dark') {
        document.documentElement.classList.add('dark');
      }
    </script>

    <!-- Allow pages to inject additional <head> content -->
    @stack('head')
  </head>

  <body class="is-header-blur is-sidebar-open">

    <!-- ============================================================
         App Preloader
    ============================================================ -->
    <!--
    <div class="app-preloader fixed z-50 grid h-full w-full place-content-center bg-slate-50 dark:bg-navy-900">
      <div class="app-preloader-inner relative inline-block size-48"></div>
    </div>
    Note: If you want to use the preloader, add the 'cloak' class to the #root div below
    -->

    <!-- Page Wrapper -->
    <div id="root" class="min-h-100vh flex grow bg-slate-50 dark:bg-navy-900">

      <!-- Sidebar -->
      @include('partials.left-sidebar')

      <!-- App Header Wrapper-->
      @include('partials.header')

      <!-- Mobile Searchbar -->
      @include('partials.mobile-searchbar')

      <!-- Right Sidebar -->
      @include('partials.right-sidebar')

      <!-- Main Content Wrapper -->
      <main class="main-content w-full px-[var(--margin-x)] pb-8">
        <div class="flex items-center space-x-4 py-5 lg:py-6">
          <h2 class="text-xl font-medium text-slate-800 dark:text-navy-50 lg:text-2xl">Current Page Heading</h2>
        </div>
        @yield('content')
      </main>

    </div>

  </body>
</html>
