<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\TickerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SearchController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\TickerAnalysisController;

// =========================
// Public Routes
// =========================

// Homepage (Blade view)
Route::get('/', [HomeController::class, 'index'])->name('home');

// Redirect /reset-password (no token) to the forgot-password page
Route::get('/reset-password', fn() => redirect()->route('password.request'));

// Guest-only auth pages
Route::middleware('guest')->group(function () {
    Route::get('/login', fn() => Inertia::render('auth/Login'))->name('login');
    Route::get('/register', fn() => Inertia::render('auth/Register'))->name('register');
    Route::get('/forgot-password', fn() => Inertia::render('auth/ForgotPassword'))->name('password.request');

    // Reset Password Routes
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->name('password.update');
});

// Search
Route::get('/search', [SearchController::class, 'searchForm'])->name('search.form');
Route::post('/search', [SearchController::class, 'performSearch'])->name('search.perform');

// Canonical ticker profile (Inertia)
Route::get('/tickers/{symbol}/{slug?}', [TickerController::class, 'show'])
    ->where('symbol', '[A-Za-z0-9\.\-]+')
    ->name('tickers.show');

// Convenience redirect /ticker/{symbol}
Route::get('/ticker/{symbol}', fn($symbol) =>
    redirect()->route('tickers.show', ['symbol' => $symbol])
);

// =========================
// Authenticated Routes
// =========================
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', fn() => Inertia::render('Dashboard'))->name('dashboard');

    // -----------------------------------------
    // Ticker Analysis (AI Generation) Routes
    // -----------------------------------------
    Route::prefix('analysis')->name('analysis.')->group(function () {
        Route::post('/request', [TickerAnalysisController::class, 'requestAnalysis'])
            ->middleware('throttle:60,1') // optional secondary limiter (60 req/min)
            ->name('request');

        Route::get('/{id}', [TickerAnalysisController::class, 'show'])
            ->name('show');
    });
});

// =========================
// Email Verification Routes
// =========================
Route::middleware(['auth'])->group(function () {
    Route::get('/email/verify', fn() => Inertia::render('auth/VerifyEmail'))
        ->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();
        return redirect()->route('dashboard');
    })->middleware(['signed'])->name('verification.verify');

    Route::post('/email/verification-notification', function (Illuminate\Http\Request $request) {
        $request->user()->sendEmailVerificationNotification();
        return back()->with('status', 'verification-link-sent');
    })->middleware(['throttle:6,1'])->name('verification.send');
});

// =========================
// Additional route includes
// =========================
require __DIR__ . '/settings.php';