<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('login', function (Request $request): array {
            $rawEmail = $request->input('email');
            $email = is_string($rawEmail) ? Str::transliterate(Str::lower(trim($rawEmail))) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by($email.'|'.$ip),
                Limit::perMinute(30)->by('login-ip|'.$ip),
            ];
        });

        RateLimiter::for('track', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('create-report', function (Request $request): Limit {
            return Limit::perHour(10)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }
}
