<?php

declare(strict_types=1);

namespace App\Support\Cache;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CacheVersionRegistry
{
    public function __construct(private readonly CacheKeyFactory $keys) {}

    public function version(CacheDomain $domain, string $scope = 'public'): int
    {
        $key = $this->keys->version($domain, $scope);

        try {
            $store = Cache::memo($this->store());
            $version = $store->get($key);

            if (is_int($version) && $version > 0) {
                return $version;
            }

            $store->add($key, 1, $this->retention());
            $store->add($this->keys->modified($domain, $scope), now()->getTimestamp(), $this->retention());

            return max(1, (int) $store->get($key, 1));
        } catch (Throwable $exception) {
            report($exception);

            throw new CacheVersionUnavailable('Реестр версий кэша недоступен.', previous: $exception);
        }
    }

    public function bump(CacheDomain $domain, string $scope = 'public'): int
    {
        $key = $this->keys->version($domain, $scope);

        try {
            $store = Cache::memo($this->store());
            $store->add($key, 1, $this->retention());
            $version = $store->increment($key);
            $store->touch($key, $this->retention());
            $store->put($this->keys->modified($domain, $scope), now()->getTimestamp(), $this->retention());

            return max(2, (int) $version);
        } catch (Throwable $exception) {
            report($exception);

            throw new CacheVersionUnavailable('Инвалидация версии кэша недоступна.', previous: $exception);
        }
    }

    public function lastModified(CacheDomain $domain, string $scope = 'public'): DateTimeImmutable
    {
        $key = $this->keys->modified($domain, $scope);

        try {
            $store = Cache::memo($this->store());
            $fallback = max(1, (int) @filemtime(base_path('composer.lock')));
            $store->add($key, $fallback, $this->retention());
            $timestamp = max(1, (int) $store->get($key, $fallback));

            return (new DateTimeImmutable)->setTimestamp($timestamp);
        } catch (Throwable $exception) {
            report($exception);

            return (new DateTimeImmutable)->setTimestamp(max(1, (int) @filemtime(base_path('composer.lock'))));
        }
    }

    private function store(): string
    {
        return (string) config('cache-architecture.stores.versions', 'redis-locks');
    }

    private function retention(): int
    {
        return max(60, (int) config('cache-architecture.version_retention_seconds', 31_536_000));
    }
}
