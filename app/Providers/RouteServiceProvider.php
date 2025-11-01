<?php

namespace App\Providers;

use App\Models\Ticker;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/';

    public function boot(): void
    {
        parent::boot();

        // Case-insensitive ticker lookup for {symbol} parameter
        Route::bind('symbol', function ($value) {
            return Ticker::whereRaw('upper(ticker) = ?', [strtoupper($value)])->firstOrFail();
        });

        // Load web and API routes
        $this->routes(function () {

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::prefix('api')
                ->middleware('api')
                ->group(base_path('routes/api.php'));
        });
    }
}