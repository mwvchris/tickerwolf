<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind custom LoginResponse to handle post-login redirects
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Configure Fortify Inertia views.
     */
    private function configureViews(): void
    {
        // Login
        Fortify::loginView(function (Request $request) {
            if ($request->user()) {
                return redirect()->intended('/dashboard');
            }

            return Inertia::render('auth/Login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'canRegister' => Features::enabled(Features::registration()),
                'status' => $request->session()->get('status'),
            ]);
        });

        // Registration
        Fortify::registerView(function (Request $request) {
            if ($request->user()) {
                return redirect()->intended('/dashboard');
            }

            return Inertia::render('auth/Register');
        });

        // Forgot Password
        Fortify::requestPasswordResetLinkView(function (Request $request) {
            if ($request->user()) {
                return redirect()->intended('/dashboard');
            }

            return Inertia::render('auth/ForgotPassword', [
                'status' => $request->session()->get('status'),
            ]);
        });

        // Reset Password
        Fortify::resetPasswordView(function (Request $request) {
            if ($request->user()) {
                return redirect()->intended('/dashboard');
            }

            return Inertia::render('auth/ResetPassword', [
                'email' => $request->email,
                'token' => $request->route('token'),
            ]);
        });

        // Verify Email
        Fortify::verifyEmailView(function (Request $request) {
            return Inertia::render('auth/VerifyEmail', [
                'status' => $request->session()->get('status'),
            ]);
        });

        // Two Factor Challenge
        Fortify::twoFactorChallengeView(function () {
            return Inertia::render('auth/TwoFactorChallenge');
        });

        // Confirm Password
        Fortify::confirmPasswordView(function () {
            return Inertia::render('auth/ConfirmPassword');
        });
    }

    /**
     * Configure rate limiting for authentication actions.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())) . '|' . $request->ip());
            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
