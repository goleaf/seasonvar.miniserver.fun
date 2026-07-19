<?php

declare(strict_types=1);

namespace App\Services\UserPortal;

use App\Models\User;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\TieredCache;
use Closure;
use RuntimeException;
use Throwable;

final readonly class UserPortalCache
{
    public function __construct(
        private TieredCache $cache,
        private CacheTtlPolicy $ttl,
    ) {}

    /**
     * @param  array<string, mixed>  $dimensions
     * @param  Closure(): array<string|int, mixed>  $rebuild
     * @return array<string|int, mixed>
     */
    public function remember(
        User $user,
        string $resource,
        array $dimensions,
        Closure $rebuild,
        bool $refresh = false,
    ): array {
        $scope = $this->scope($user);
        $dimensions = [
            'cache_projection' => 'user-portal-v1',
            'owner_scope' => hash('sha256', $scope),
            ...$dimensions,
        ];

        try {
            $window = $this->ttl->for(CacheDomain::UserPortal);
        } catch (Throwable $exception) {
            report($exception);

            return $this->arrayPayload($rebuild());
        }

        $result = $refresh
            ? $this->cache->refresh(
                CacheDomain::UserPortal,
                $resource,
                $dimensions,
                $window,
                $rebuild,
                versionScope: $scope,
            )
            : $this->cache->remember(
                CacheDomain::UserPortal,
                $resource,
                $dimensions,
                $window,
                $rebuild,
                versionScope: $scope,
            );

        return $this->arrayPayload($result->value);
    }

    public function scope(User $user): string
    {
        $publicId = mb_strtolower(trim((string) $user->public_id));

        if (preg_match('/^[a-f0-9-]{36}$/D', $publicId) !== 1) {
            throw new RuntimeException('У пользователя отсутствует стабильная cache identity.');
        }

        return 'u-'.$publicId;
    }

    /** @return array<string|int, mixed> */
    private function arrayPayload(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Owner cache вернул неподдерживаемый payload.');
        }

        return $value;
    }
}
