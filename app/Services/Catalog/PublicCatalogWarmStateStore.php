<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\PublicCacheWarmBatch;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class PublicCatalogWarmStateStore
{
    public function __construct(private readonly CacheKeyFactory $keys) {}

    /** @return array<string, mixed> */
    public function start(bool $refresh, int $estimated = 0): array
    {
        return $this->synchronized(function (Repository $store) use ($refresh, $estimated): array {
            $current = $this->state($store);

            if ($current !== null && in_array($current['status'], ['queued', 'running'], true)) {
                return $current;
            }

            $state = [
                'generation' => (string) Str::uuid(),
                'status' => 'queued',
                'refresh' => $refresh,
                'cursor' => null,
                'estimated' => max(0, $estimated),
                'attempted' => 0,
                'warmed' => 0,
                'failed' => 0,
                'last_errors' => [],
                'started_at' => null,
                'updated_at' => now()->toIso8601String(),
                'finished_at' => null,
            ];
            $this->write($store, $state);

            return $state;
        });
    }

    /** @return array<string, mixed>|null */
    public function resume(): ?array
    {
        return $this->synchronized(function (Repository $store): ?array {
            $state = $this->state($store);

            if ($state === null || ! in_array($state['status'], ['queued', 'running', 'failed'], true)) {
                return null;
            }

            $state['status'] = 'queued';
            $state['updated_at'] = now()->toIso8601String();
            $state['finished_at'] = null;
            $this->write($store, $state);

            return $state;
        });
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        $repository = Cache::store($this->store());

        return $repository instanceof Repository ? $this->state($repository) : null;
    }

    /** @return array<string, mixed>|null */
    public function markRunning(string $generation): ?array
    {
        return $this->synchronized(function (Repository $store) use ($generation): ?array {
            $state = $this->state($store);

            if ($state === null
                || $state['generation'] !== $generation
                || ! in_array($state['status'], ['queued', 'running', 'failed'], true)) {
                return null;
            }

            $state['status'] = 'running';
            $state['started_at'] ??= now()->toIso8601String();
            $state['updated_at'] = now()->toIso8601String();
            $this->write($store, $state);

            return $state;
        });
    }

    /**
     * @param  array{attempted: int, succeeded: int, failed: int, errors: list<array{fingerprint: string, status: int|null, exception: string|null}>}  $result
     * @return array<string, mixed>|null
     */
    public function advance(string $generation, PublicCacheWarmBatch $batch, array $result): ?array
    {
        return $this->synchronized(function (Repository $store) use ($generation, $batch, $result): ?array {
            $state = $this->state($store);

            if ($state === null || $state['generation'] !== $generation) {
                return null;
            }

            $state['cursor'] = $batch->nextCursor;
            $state['attempted'] += max(0, $result['attempted']);
            $state['warmed'] += max(0, $result['succeeded']);
            $state['failed'] += max(0, $result['failed']);
            $state['last_errors'] = array_slice(
                [...$state['last_errors'], ...$result['errors']],
                -$this->errorLimit(),
            );
            $state['updated_at'] = now()->toIso8601String();

            if ($batch->completed) {
                $state['status'] = $state['failed'] > 0 ? 'completed_with_failures' : 'completed';
                $state['finished_at'] = now()->toIso8601String();
            } else {
                $state['status'] = 'running';
            }

            $this->write($store, $state);

            return $state;
        });
    }

    public function failed(string $generation, ?Throwable $exception): void
    {
        $this->synchronized(function (Repository $store) use ($generation, $exception): void {
            $state = $this->state($store);

            if ($state === null || $state['generation'] !== $generation) {
                return;
            }

            $state['status'] = 'failed';
            $state['updated_at'] = now()->toIso8601String();
            $state['finished_at'] = now()->toIso8601String();

            if ($exception !== null) {
                $state['last_errors'] = array_slice([
                    ...$state['last_errors'],
                    [
                        'fingerprint' => hash('sha256', $exception::class),
                        'status' => null,
                        'exception' => $exception::class,
                    ],
                ], -$this->errorLimit());
            }

            $this->write($store, $state);
        });
    }

    /** @return array<string, mixed>|null */
    private function state(Repository $store): ?array
    {
        $value = $store->get($this->key());

        if (! is_array($value)
            || ! is_string($value['generation'] ?? null)
            || ! is_string($value['status'] ?? null)) {
            return null;
        }

        $cursor = $value['cursor'] ?? null;

        if ($cursor !== null && ! is_array($cursor)) {
            $cursor = null;
        }

        $rawErrors = $value['last_errors'] ?? [];
        $errors = is_array($rawErrors)
            ? array_values(array_filter(
                $rawErrors,
                fn (mixed $error): bool => is_array($error)
                    && is_string($error['fingerprint'] ?? null),
            ))
            : [];
        $errors = array_slice($errors, -$this->errorLimit());

        return [
            'generation' => $value['generation'],
            'status' => $value['status'],
            'refresh' => (bool) ($value['refresh'] ?? false),
            'cursor' => $cursor,
            'estimated' => max(0, (int) ($value['estimated'] ?? 0)),
            'attempted' => max(0, (int) ($value['attempted'] ?? 0)),
            'warmed' => max(0, (int) ($value['warmed'] ?? 0)),
            'failed' => max(0, (int) ($value['failed'] ?? 0)),
            'last_errors' => $errors,
            'started_at' => is_string($value['started_at'] ?? null) ? $value['started_at'] : null,
            'updated_at' => is_string($value['updated_at'] ?? null) ? $value['updated_at'] : null,
            'finished_at' => is_string($value['finished_at'] ?? null) ? $value['finished_at'] : null,
        ];
    }

    /** @param array<string, mixed> $state */
    private function write(Repository $store, array $state): void
    {
        $store->put(
            $this->key(),
            $state,
            max(60, (int) config('cache-architecture.warming.full_state_retention_seconds', 2_592_000)),
        );
    }

    /** @param Closure(Repository): mixed $callback */
    private function synchronized(Closure $callback): mixed
    {
        $repository = Cache::store($this->store());

        if (! $repository instanceof Repository) {
            throw new LogicException('Cache store полного прогрева должен использовать Laravel cache repository.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Cache store полного прогрева должен поддерживать atomic locks.');
        }

        return $store->lock(
            $this->keys->lock($this->key()),
            max(1, (int) config('cache-architecture.warming.request_lock_seconds', 10)),
        )->block(
            max(1, (int) config('cache-architecture.warming.request_lock_wait_seconds', 2)),
            fn (): mixed => $callback($repository),
        );
    }

    private function errorLimit(): int
    {
        return max(1, (int) config('cache-architecture.warming.full_error_limit', 100));
    }

    private function key(): string
    {
        return $this->keys->data(CacheDomain::Operational, 'public-catalog-cache-warm-state', [], 1);
    }

    private function store(): string
    {
        return (string) config('cache-architecture.stores.locks', 'redis-locks');
    }
}
