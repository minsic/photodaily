<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
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
        // In produzione Caddy parla con PHP-FPM direttamente e l'host è quello vero.
        // In sviluppo il proxy di Vite inoltra a Herd: l'host del frontend
        // arriva in X-Forwarded-Host, da accettare solo da quel proxy.
        if (filled(config('photodaily.trusted_proxies'))) {
            TrustProxies::at(config('photodaily.trusted_proxies'));
        }

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('invites', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Bruteforce sulla password condivisa di una famiglia.
        RateLimiter::for('public-password', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
