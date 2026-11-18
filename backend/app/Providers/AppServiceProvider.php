<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('rate_limits.login_per_minute'))
            ->by('login:'.$request->ip())
            ->response(fn () => response()->json(['message' => 'Too many requests.'], 429)));

        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(config('rate_limits.registration_per_hour'))
            ->by('registration:'.$request->ip())
            ->response(fn () => response()->json(['message' => 'Too many requests.'], 429)));

        RateLimiter::for('qr-credentials', fn (Request $request) => Limit::perMinute(config('rate_limits.qr_credentials_per_minute'))
            ->by('qr-credentials:user:'.$request->user()->getAuthIdentifier())
            ->response(fn () => response()->json(['message' => 'Too many requests.'], 429)));

        RateLimiter::for('staff-scans', fn (Request $request) => Limit::perMinute(config('rate_limits.staff_scans_per_minute'))
            ->by('staff-scans:user:'.$request->user()->getAuthIdentifier())
            ->response(fn () => response()->json(['message' => 'Too many requests.'], 429)));
    }
}
