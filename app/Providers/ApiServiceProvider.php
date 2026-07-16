<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\AuthenticationFingerprint;
use App\ValueObjects\NormalizedEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('mobile-register', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('register|'.$this->networkFingerprint($request)));

        RateLimiter::for('mobile-login', function (Request $request): Limit {
            $email = NormalizedEmail::value((string) $request->input('email'));

            return Limit::perMinute(5)->by('login|'.$this->emailFingerprint($email).'|'.$this->networkFingerprint($request));
        });

        foreach (['mobile-forgot-password', 'mobile-reset-password'] as $limiter) {
            RateLimiter::for($limiter, function (Request $request) use ($limiter): Limit {
                $email = NormalizedEmail::value((string) $request->input('email'));

                return Limit::perMinutes(10, 3)->by($limiter.'|'.$this->emailFingerprint($email).'|'.$this->networkFingerprint($request));
            });
        }

        RateLimiter::for('mobile-verification', fn (Request $request): Limit => Limit::perMinute(3)
            ->by('verification|'.($request->user()?->getAuthIdentifier() ?? $this->networkFingerprint($request))));

        RateLimiter::for('mobile-token-refresh', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken
                ? app(AuthenticationFingerprint::class)->opaque('token', (string) $token->getKey())
                : $this->networkFingerprint($request);

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

        return 'ip:'.$this->networkFingerprint($request);
    }

    private function emailFingerprint(string $email): string
    {
        return app(AuthenticationFingerprint::class)->email($email);
    }

    private function networkFingerprint(Request $request): string
    {
        return app(AuthenticationFingerprint::class)->network($request->ip());
    }
}
