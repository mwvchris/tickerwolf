<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Models\Ticker;
use App\Http\Controllers\TickerAnalysisController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are automatically prefixed with /api.
| Updated for Laravel 12 + Sanctum SPA authentication support.
|
| Notes:
| - Sanctum's EnsureFrontendRequestsAreStateful middleware is already
|   registered in bootstrap/app.php (as you configured).
| - The /sanctum/csrf-cookie route is handled automatically by Sanctum.
|
*/

// ---------------------------------------------------------
// 🔒 Authenticated user endpoint (required by Sanctum)
// ---------------------------------------------------------
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return response()->json($request->user());
});

// ---------------------------------------------------------
// 🤖 Public AI provider list (no auth required)
// ---------------------------------------------------------
Route::get('/ai/providers', fn () => response()->json([
    'providers' => [
        ['id' => 'openai', 'name' => 'OpenAI (GPT-4)'],
        ['id' => 'gemini', 'name' => 'Gemini (Google Gemini)'],
        ['id' => 'grok', 'name' => 'Grok (xAI)'],
    ],
]));

// ---------------------------------------------------------
// 🧠 Authenticated AI-powered ticker analysis
// ---------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/ai/analysis', [TickerAnalysisController::class, 'requestAnalysis'])
        ->name('api.ai.analysis.request');

    Route::get('/ai/analysis/{id}', [TickerAnalysisController::class, 'show'])
        ->name('api.ai.analysis.show');
});

// ---------------------------------------------------------
// 🔍 Public ticker search (autocomplete)
// ---------------------------------------------------------
Route::get('/tickers/search', function (Request $request) {
    $q = trim($request->get('q', ''));

    if ($q === '' || strlen($q) < 1) {
        return response()->json([]);
    }

    return Ticker::query()
        ->where('ticker', 'like', "%{$q}%")
        ->orWhere('name', 'like', "%{$q}%")
        ->limit(10)
        ->get(['id', 'ticker', 'name']);
});