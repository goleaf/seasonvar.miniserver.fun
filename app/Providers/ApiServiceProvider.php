<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('mobile-register', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('register|'.$request->ip()));

        RateLimiter::for('mobile-login', function (Request $request): Limit {
            $email = Str::lower(Str::squish((string) $request->input('email')));

            return Limit::perMinute(5)->by('login|'.$email.'|'.$request->ip());
        });

        foreach (['mobile-forgot-password', 'mobile-reset-password'] as $limiter) {
            RateLimiter::for($limiter, function (Request $request) use ($limiter): Limit {
                $email = Str::lower(Str::squish((string) $request->input('email')));

                return Limit::perMinutes(10, 3)->by($limiter.'|'.$email.'|'.$request->ip());
            });
        }

        RateLimiter::for('mobile-verification', fn (Request $request): Limit => Limit::perMinute(3)
            ->by('verification|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
