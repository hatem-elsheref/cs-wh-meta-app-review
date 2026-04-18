<?php

namespace App\Providers;

use App\Services\MetaWhatsAppService;
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
        $this->app->singleton(MetaWhatsAppService::class, function () {
            return new MetaWhatsAppService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('webhook-whatsapp', function (Request $request) {
            return Limit::perMinute(500)->by($request->ip());
        });

        RateLimiter::for('external-whatsapp', function (Request $request) {
            $key = $request->header('X-API-Key');

            return Limit::perMinute(120)->by(is_string($key) && $key !== '' ? sha1($key) : $request->ip());
        });

        RateLimiter::for('api-authenticated', function (Request $request) {
            return Limit::perMinute(300)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
