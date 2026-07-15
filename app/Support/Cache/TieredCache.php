<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

use function Illuminate\Support\defer;

final class TieredCache
{
    private const ENVELOPE_FORMAT = 1;

    public function __construct(
        private readonly CacheKeyFactory $keys,
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
    ) {}

    /**
     * @param  array<string, mixed>  $dimensions
     * @param  Closure(): mixed  $rebuild
     */
    public function remember(
        CacheDomain $domain,
        string $resource,
        array $dimensions,
        CacheWindow $window,
        Closure $rebuild,
        bool $cacheNull = false,
        string $versionScope = 'public',
    ): TieredCacheResult {
        try {
            $version = $this->versions->version($domain, $versionScope);
        } catch (CacheVersionUnavailable $exception) {
            $this->reportFailure($domain, 'version-read', $exception);

            return $this->rebuildWithoutCache($domain, $rebuild);
        }

        $key = $this->keys->data($domain, $resource, $dimensions, $version);

        $hot = $this->read($this->hotStore(), $key, $domain, 'hot');

        if ($hot !== null) {
            return $this->result($hot, 'hot');
        }

        $shared = $this->read($this->domainStore(), $key, $domain, 'shared');

        if ($shared !== null) {
            $this->write($this->hotStore(), $key, $shared, $window->jitteredHotSeconds(), $domain, 'hot');

            return $this->result($shared, 'shared');
        }

        $staleKey = $this->keys->stale($key);
        $stale = $this->read($this->domainStore(), $staleKey, $domain, 'stale');

        if ($stale !== null) {
            $this->scheduleStaleRebuild($domain, $key, $staleKey, $window, $rebuild, $cacheNull);
            $this->telemetry->increment($domain, 'stale-served');

            return $this->result($stale, 'stale', stale: true);
        }

        $lock = null;

        try {
            $lock = $this->lock($this->keys->lock($key), $window->lockSeconds);

            if (! $lock->get()) {
                return $this->waitForRebuild($domain, $key, $window);
            }
        } catch (CacheRebuildTimeout $exception) {
            $this->reportFailure($domain, 'lock-timeout-fallback', $exception);

            return $this->rebuildWithoutCache($domain, $rebuild);
        } catch (Throwable $exception) {
            $this->reportFailure($domain, 'lock', $exception);

            return $this->rebuildOrStale(
                $domain,
                $key,
                $staleKey,
                $window,
                $rebuild,
                $cacheNull,
                $stale,
                cacheWrites: false,
            );
        }

        try {
            $secondCheck = $this->read($this->domainStore(), $key, $domain, 'shared');

            if ($secondCheck !== null) {
                return $this->result($secondCheck, 'shared');
            }

            return $this->rebuildOrStale($domain, $key, $staleKey, $window, $rebuild, $cacheNull, $stale);
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                $this->reportFailure($domain, 'lock-release', $exception);
            }
        }
    }

    /**
     * Rebuild the active namespace without deleting its currently readable
     * fresh or stale values. The new envelopes replace them only after the
     * authoritative rebuild has completed successfully.
     *
     * @param  array<string, mixed>  $dimensions
     * @param  Closure(): mixed  $rebuild
     */
    public function refresh(
        CacheDomain $domain,
        string $resource,
        array $dimensions,
        CacheWindow $window,
        Closure $rebuild,
        bool $cacheNull = false,
        string $versionScope = 'public',
    ): TieredCacheResult {
        try {
            $version = $this->versions->version($domain, $versionScope);
        } catch (CacheVersionUnavailable $exception) {
            $this->reportFailure($domain, 'version-read', $exception);

            return $this->rebuildWithoutCache($domain, $rebuild);
        }

        $key = $this->keys->data($domain, $resource, $dimensions, $version);
        try {
            $lock = $this->lock($this->keys->lock($key), $window->lockSeconds);
            $deadline = hrtime(true) + ($window->waitMilliseconds * 1_000_000);

            while (! $lock->get()) {
                if (hrtime(true) >= $deadline) {
                    $this->telemetry->increment($domain, 'lock-timeout');

                    throw new CacheRebuildTimeout("Не удалось получить блокировку обновления кэша {$domain->value}.");
                }

                usleep(10_000);
            }
        } catch (CacheRebuildTimeout $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->reportFailure($domain, 'refresh-lock', $exception);

            throw new CacheRebuildTimeout(
                "Блокировка обновления кэша {$domain->value} недоступна.",
                previous: $exception,
            );
        }

        try {
            return $this->rebuild(
                $domain,
                $key,
                $this->keys->stale($key),
                $window,
                $rebuild,
                $cacheNull,
            );
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                $this->reportFailure($domain, 'lock-release', $exception);
            }
        }
    }

    /** @param Closure(): mixed $rebuild */
    private function scheduleStaleRebuild(
        CacheDomain $domain,
        string $key,
        string $staleKey,
        CacheWindow $window,
        Closure $rebuild,
        bool $cacheNull,
    ): void {
        try {
            $lock = $this->lock($this->keys->lock($key), $window->lockSeconds);

            if (! $lock->get()) {
                return;
            }

            defer(function () use ($cacheNull, $domain, $key, $lock, $rebuild, $staleKey, $window): void {
                try {
                    $this->rebuild($domain, $key, $staleKey, $window, $rebuild, $cacheNull);
                } catch (Throwable $exception) {
                    $this->reportFailure($domain, 'stale-rebuild', $exception);
                } finally {
                    try {
                        $lock->release();
                    } catch (Throwable $exception) {
                        $this->reportFailure($domain, 'lock-release', $exception);
                    }
                }
            })->name('cache-rebuild:'.$domain->value.':'.hash('xxh3', $key));
        } catch (Throwable $exception) {
            $this->reportFailure($domain, 'stale-lock', $exception);
        }
    }

    /**
     * @param  Closure(): mixed  $rebuild
     * @param  array{format: int, negative: bool, value: mixed}|null  $stale
     */
    private function rebuildOrStale(
        CacheDomain $domain,
        string $key,
        string $staleKey,
        CacheWindow $window,
        Closure $rebuild,
        bool $cacheNull,
        ?array $stale,
        bool $cacheWrites = true,
    ): TieredCacheResult {
        try {
            return $this->rebuild($domain, $key, $staleKey, $window, $rebuild, $cacheNull, $cacheWrites);
        } catch (Throwable $exception) {
            if ($stale === null) {
                throw $exception;
            }

            $this->telemetry->increment($domain, 'stale-served-on-error');
            Log::warning('При перестроении прикладного кэша использован ограниченный stale-снимок.', [
                'cache_domain' => $domain->value,
                'exception' => $exception::class,
            ]);

            return $this->result($stale, 'stale', stale: true);
        }
    }

    private function waitForRebuild(CacheDomain $domain, string $key, CacheWindow $window): TieredCacheResult
    {
        $deadline = hrtime(true) + ($window->waitMilliseconds * 1_000_000);

        do {
            usleep(10_000);
            $value = $this->read($this->domainStore(), $key, $domain, 'shared');

            if ($value !== null) {
                return $this->result($value, 'shared');
            }
        } while (hrtime(true) < $deadline);

        $this->telemetry->increment($domain, 'lock-timeout');

        throw new CacheRebuildTimeout("Не удалось дождаться перестроения кэша {$domain->value}.");
    }

    /** @param Closure(): mixed $rebuild */
    private function rebuildWithoutCache(CacheDomain $domain, Closure $rebuild): TieredCacheResult
    {
        $startedAt = hrtime(true);

        try {
            $value = $rebuild();
        } catch (Throwable $exception) {
            $this->telemetry->increment($domain, 'rebuild-failure');
            throw $exception;
        }

        $this->telemetry->duration($domain, 'rebuild', (int) ((hrtime(true) - $startedAt) / 1_000_000));

        return new TieredCacheResult($value, 'rebuild', negative: $value === null);
    }

    /** @param Closure(): mixed $rebuild */
    private function rebuild(
        CacheDomain $domain,
        string $key,
        string $staleKey,
        CacheWindow $window,
        Closure $rebuild,
        bool $cacheNull,
        bool $cacheWrites = true,
    ): TieredCacheResult {
        $startedAt = hrtime(true);

        try {
            $value = $rebuild();
        } catch (Throwable $exception) {
            $this->telemetry->increment($domain, 'rebuild-failure');
            throw $exception;
        }

        $negative = $value === null;
        $envelope = $this->envelope($value, $negative);
        $payloadBytes = strlen(serialize($envelope));
        $this->telemetry->duration($domain, 'rebuild', (int) ((hrtime(true) - $startedAt) / 1_000_000));
        $this->telemetry->increment($domain, 'cache-payload-count');
        $this->telemetry->increment($domain, 'cache-payload-bytes', max(1, $payloadBytes));

        if (($negative && ! $cacheNull)
            || $payloadBytes > max(1, (int) config('cache-architecture.max_payload_bytes', 900_000))
            || ! $cacheWrites) {
            if ($payloadBytes > (int) config('cache-architecture.max_payload_bytes', 900_000)) {
                $this->telemetry->increment($domain, 'payload-rejected');
            }

            return new TieredCacheResult($value, 'rebuild', negative: $negative);
        }

        $freshTtl = $negative ? $window->jitteredNegativeSeconds() : $window->jitteredFreshSeconds();
        $hotTtl = $negative ? $window->jitteredNegativeSeconds() : $window->jitteredHotSeconds();
        $this->write($this->domainStore(), $key, $envelope, $freshTtl, $domain, 'shared');
        $this->write($this->hotStore(), $key, $envelope, $hotTtl, $domain, 'hot');

        if (! $negative) {
            $this->write($this->domainStore(), $staleKey, $envelope, $window->staleSeconds, $domain, 'stale');
        }

        return new TieredCacheResult($value, 'rebuild', negative: $negative);
    }

    /** @return array{format: int, negative: bool, value: mixed} */
    private function envelope(mixed $value, bool $negative): array
    {
        return [
            'format' => self::ENVELOPE_FORMAT,
            'negative' => $negative,
            'value' => $value,
        ];
    }

    /** @return array{format: int, negative: bool, value: mixed}|null */
    private function read(string $store, string $key, CacheDomain $domain, string $layer): ?array
    {
        try {
            $value = Cache::memo($store)->get($key);

            if (is_array($value) && ($value['format'] ?? null) === self::ENVELOPE_FORMAT && array_key_exists('value', $value)) {
                $this->telemetry->increment($domain, $layer.'-hit');

                return [
                    'format' => self::ENVELOPE_FORMAT,
                    'negative' => (bool) ($value['negative'] ?? false),
                    'value' => $value['value'],
                ];
            }

            $this->telemetry->increment($domain, $layer.'-miss');
        } catch (Throwable $exception) {
            $this->reportFailure($domain, $layer.'-read', $exception);
        }

        return null;
    }

    /** @param array{format: int, negative: bool, value: mixed} $envelope */
    private function write(string $store, string $key, array $envelope, int $ttl, CacheDomain $domain, string $layer): void
    {
        try {
            Cache::memo($store)->put($key, $envelope, $ttl);
            $this->telemetry->increment($domain, $layer.'-write');
        } catch (Throwable $exception) {
            $this->reportFailure($domain, $layer.'-write', $exception);
        }
    }

    /** @param array{format: int, negative: bool, value: mixed} $envelope */
    private function result(array $envelope, string $source, bool $stale = false): TieredCacheResult
    {
        return new TieredCacheResult(
            value: $envelope['value'],
            source: $source,
            stale: $stale,
            negative: $envelope['negative'],
        );
    }

    private function reportFailure(CacheDomain $domain, string $operation, Throwable $exception): void
    {
        $this->telemetry->increment($domain, 'failure');
        Log::warning('Операция прикладного кэша завершилась ошибкой.', [
            'cache_domain' => $domain->value,
            'operation' => $operation,
            'exception' => $exception::class,
        ]);
    }

    private function hotStore(): string
    {
        return (string) config('cache-architecture.stores.hot', 'memcached-hot');
    }

    private function domainStore(): string
    {
        return (string) config('cache-architecture.stores.domain', 'redis-domain');
    }

    private function lockStore(): string
    {
        return (string) config('cache-architecture.stores.locks', 'redis-locks');
    }

    private function lock(string $name, int $seconds): Lock
    {
        $repository = Cache::store($this->lockStore());

        if (! $repository instanceof Repository) {
            throw new LogicException('Cache store блокировок должен использовать Laravel cache repository.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Cache store блокировок должен поддерживать atomic locks.');
        }

        return $store->lock($name, $seconds);
    }
}
