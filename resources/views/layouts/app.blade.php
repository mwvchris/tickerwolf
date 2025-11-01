<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('meta_description', 'Ticker profiles and search')">

    <!-- Tailwind compiled CSS (assume using Vite or Mix) -->
    @vite('resources/css/app.css')
    @vite('resources/js/app.js')
</head>
<body class="bg-gray-50 text-gray-900">
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4 py-4 flex items-center justify-between">
            <a href="{{ route('home') }}" class="font-semibold text-lg">{{ config('app.name', 'TickersApp') }}</a>

            <form action="{{ route('search.perform') }}" method="POST" class="flex">
                @csrf
                <input name="q" type="text" placeholder="Search ticker or company" class="border rounded-l px-3 py-2 w-64 focus:outline-none focus:ring" />
                <button type="submit" class="bg-blue-600 text-white rounded-r px-4">Search</button>
            </form>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        @if(session('error'))
            <div class="mb-4 text-sm text-red-700 bg-red-100 p-3 rounded">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>

    <footer class="text-sm text-gray-600 py-6 border-t mt-8">
        <div class="container mx-auto px-4 text-center">© {{ date('Y') }} {{ config('app.name') }}</div>
    </footer>
</body>
</html>
