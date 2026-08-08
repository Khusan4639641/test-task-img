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
        RateLimiter::for('register', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by('register:'.$request->ip()));

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('login:'.hash('sha256', mb_strtolower((string) $request->input('email'))).'|'.$request->ip()));

        RateLimiter::for('uploads', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('uploads:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
