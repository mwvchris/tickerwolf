@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-8 space-y-8">

    <!-- ===== Ticker Profile & Stats Grid ===== -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Left Column: Profile Header -->
        <div class="bg-white shadow rounded-2xl p-6 flex flex-col items-center text-center">
            <!-- Placeholder Logo -->
            <div class="w-28 h-28 bg-gray-200 rounded-full flex items-center justify-center mb-4">
                <span class="text-gray-400 text-xl font-semibold">Logo</span>
            </div>

            <!-- Ticker Name & Symbol -->
            <h1 class="text-3xl font-bold text-gray-900">{{ $ticker->ticker }}</h1>
            <p class="text-gray-600 text-lg mb-4">{{ $ticker->name }}</p>

            <!-- Ticker Info -->
            <div class="space-y-2 text-gray-700 w-full">
                <div><span class="font-semibold">Market:</span> {{ $ticker->market }}</div>
                <div><span class="font-semibold">Locale:</span> {{ $ticker->locale }}</div>
                <div><span class="font-semibold">Primary Exchange:</span> {{ $ticker->primary_exchange }}</div>
                <div><span class="font-semibold">Currency:</span> {{ $ticker->currency_name }}</div>
                <div><span class="font-semibold">Type:</span> {{ $ticker->type }}</div>
                <div><span class="font-semibold">Active:</span> {{ $ticker->active ? 'Yes' : 'No' }}</div>
            </div>
        </div>

        <!-- Right Column: Quick Stats / Charts -->
        <div class="lg:col-span-2 bg-white shadow rounded-2xl p-6 flex flex-col gap-4">
            <h2 class="text-xl font-semibold text-gray-900 mb-2">Quick Stats</h2>

            <!-- Placeholder for stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-gray-50 p-4 rounded-lg text-center shadow-sm">
                    <div class="text-sm text-gray-500">Price</div>
                    <div class="text-lg font-bold text-gray-900">$123.45</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg text-center shadow-sm">
                    <div class="text-sm text-gray-500">Market Cap</div>
                    <div class="text-lg font-bold text-gray-900">$50B</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg text-center shadow-sm">
                    <div class="text-sm text-gray-500">Volume</div>
                    <div class="text-lg font-bold text-gray-900">1.2M</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg text-center shadow-sm">
                    <div class="text-sm text-gray-500">52w High / Low</div>
                    <div class="text-lg font-bold text-gray-900">$130 / $90</div>
                </div>
            </div>

            <!-- Placeholder for chart -->
            <div class="mt-6 h-64 bg-gray-100 rounded-xl flex items-center justify-center text-gray-400">
                Chart Placeholder
            </div>
        </div>

    </div>

    <!-- ===== AI Analysis Section (Full Width) ===== -->
    <div class="bg-white shadow rounded-2xl p-6">
        <h2 class="text-2xl font-semibold text-gray-900 mb-4">AI Stock Analysis</h2>
        <div
            id="ticker-analysis-app"
            data-ticker="{{ $ticker->ticker }}"
            data-user-auth="{{ Auth::check() ? 'true' : 'false' }}"
            data-login-url="{{ route('login', [], false) }}"
        ></div>
    </div>

</div>
@endsection

@vite(['resources/js/blade-app.js'])
