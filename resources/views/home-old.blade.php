@extends('layouts.app')

@section('content')
<div class="min-h-screen flex flex-col items-center justify-center bg-gray-50 p-6">
    <h1 class="text-4xl font-bold mb-6">Search Stocks</h1>

    <!-- Vue mounts autocomplete here -->
    <div id="ticker-search" class="w-80"></div>

    <p class="text-gray-600 mt-10">Or jump to a sample ticker:</p>
    <div class="mt-4 flex gap-3">
        <a href="{{ route('tickers.show', ['symbol' => 'AAPL', 'slug' => \App\Services\TickerSlugService::class ? 'apple-inc' : '']) }}" class="px-4 py-2 bg-gray-100 rounded text-sm">AAPL</a>
        <a href="{{ route('tickers.show', ['symbol' => 'MSFT', 'slug' => 'microsoft-corp']) }}" class="px-4 py-2 bg-gray-100 rounded text-sm">MSFT</a>
    </div>
</div>
@endsection

@vite('resources/js/blade-app.js')

