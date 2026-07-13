<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

final class SensitiveActionRateLimiter
{
    public function enforce(string $action, ?User $user = null, string|int|null $resource = null): void
    {
        if ($this->attempt($action, $user, $resource)) {
            return;
        }

        abort(429, 'Слишком много запросов. Повторите попытку позже.');
    }

    public function attempt(string $action, ?User $user = null, string|int|null $resource = null): bool
    {
        $actor = $user !== null
            ? 'user:'.$user->getAuthIdentifier()
            : 'ip:'.hash('sha256', (string) request()->ip());

        return $this->attemptForActor($action, $actor, $resource);
    }

    public function attemptForSystem(string $action, string|int|null $resource = null): bool
    {
        return $this->attemptForActor($action, 'system', $resource);
    }

    private function attemptForActor(string $action, string $actor, string|int|null $resource): bool
    {
        $maximum = max(1, min(1000, (int) config("security.rate_limits.{$action}", 60)));

        return RateLimiter::attempt(
            $this->key($action, $actor, $resource),
            $maximum,
            static fn (): bool => true,
            60,
        );
    }

    private function key(string $action, string $actor, string|int|null $resource): string
    {
        $resourceHash = $resource === null
            ? 'global'
            : hash('sha256', (string) $resource);

        return implode(':', ['sensitive-action', $action, $actor, $resourceHash]);
    }
}
