<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

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

        RateLimiter::for('mobile-token-refresh', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken ? $token->getKey() : $request->ip();

            return Limit::perMinute(20)->by('token-refresh|'.$key);
        });

        foreach ([
            'api-search-suggestions' => 120,
            'api-playback-session' => 30,
            'api-playback-progress' => 120,
            'api-catalog-sync' => 60,
            'api-user-sync' => 30,
        ] as $limiter => $attempts) {
            RateLimiter::for($limiter, fn (Request $request): Limit => Limit::perMinute($attempts)
                ->by($limiter.'|'.$this->requesterKey($request)));
        }
    }

    private function requesterKey(Request $request): string
    {
        $identifier = $request->user()?->getAuthIdentifier();

        if ($identifier !== null) {
            return 'user:'.(string) $identifier;
        }

        $ip = $request->ip();

        return 'ip:'.(is_string($ip) && $ip !== '' ? $ip : 'unknown');
    }
}
