<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class CatalogCollectionRateLimiter
{
    public function ensure(User $user, string $action, string $field = 'collection'): void
    {
        $policy = $this->policy($action);

        if (RateLimiter::tooManyAttempts($this->key($user, $action), $policy['attempts'])) {
            throw ValidationException::withMessages([
                $field => [__('collections.errors.rate_limited')],
            ]);
        }

        RateLimiter::hit($this->key($user, $action), $policy['decay_seconds']);
    }

    /** @return array{attempts: int, decay_seconds: int} */
    private function policy(string $action): array
    {
        $policy = config('catalog-collections.rate_limits.'.$action);
        abort_unless(is_array($policy), 404);

        return [
            'attempts' => max(1, (int) ($policy['attempts'] ?? 1)),
            'decay_seconds' => max(1, (int) ($policy['decay_seconds'] ?? 60)),
        ];
    }

    private function key(User $user, string $action): string
    {
        return 'catalog-collection:'.$action.':'.$user->getKey();
    }
}
