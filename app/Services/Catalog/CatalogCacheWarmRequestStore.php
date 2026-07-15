<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogCacheWarmWork;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use LogicException;

final class CatalogCacheWarmRequestStore
{
    public function __construct(private readonly CacheKeyFactory $keys) {}

    /** @param iterable<int, int|string> $titleIds */
    public function request(iterable $titleIds = [], bool $refresh = false): int
    {
        $titleIds = collect($titleIds)
            ->filter(fn (int|string $id): bool => is_int($id) || ctype_digit($id))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take($this->requestTitleLimit())
            ->values()
            ->all();

        return $this->synchronized(function (Repository $store) use ($refresh, $titleIds): int {
            $state = $this->state($store);
            $generation = $state['generation'] + 1;
            $state['generation'] = $generation;
            $state['pending_generation'] = $generation;
            $state['requested_at'] = now()->getTimestamp();

            if ($refresh) {
                $state['refresh_generation'] = $generation;
            }

            foreach ($titleIds as $titleId) {
                $state['title_ids'][$titleId] = $generation;
            }

            if (count($state['title_ids']) > $this->requestTitleLimit()) {
                arsort($state['title_ids'], SORT_NUMERIC);
                $state['title_ids'] = array_slice($state['title_ids'], 0, $this->requestTitleLimit(), true);
            }

            $this->write($store, $state);

            return $generation;
        });
    }

    public function claim(int $titleLimit): ?CatalogCacheWarmWork
    {
        return $this->synchronized(function (Repository $store) use ($titleLimit): ?CatalogCacheWarmWork {
            $state = $this->state($store);

            if (! $this->pending($state)) {
                return null;
            }

            return new CatalogCacheWarmWork(
                generation: $state['generation'],
                refresh: $state['refresh_generation'] > 0,
                titleIds: array_slice(
                    array_map('intval', array_keys($state['title_ids'])),
                    0,
                    max(1, min($titleLimit, $this->requestTitleLimit())),
                ),
            );
        });
    }

    /**
     * Confirm processed work and return whether another batch remains pending.
     */
    public function complete(CatalogCacheWarmWork $work): bool
    {
        return $this->synchronized(function (Repository $store) use ($work): bool {
            $state = $this->state($store);

            if ($state['pending_generation'] <= $work->generation) {
                $state['pending_generation'] = 0;
            }

            if ($state['refresh_generation'] <= $work->generation) {
                $state['refresh_generation'] = 0;
            }

            foreach ($work->titleIds as $titleId) {
                if (($state['title_ids'][$titleId] ?? PHP_INT_MAX) <= $work->generation) {
                    unset($state['title_ids'][$titleId]);
                }
            }

            $this->write($store, $state);

            return $this->pending($state);
        });
    }

    /**
     * @return array{
     *     generation: int,
     *     pending_generation: int,
     *     refresh_generation: int,
     *     requested_at: int,
     *     title_ids: array<int, int>
     * }
     */
    private function state(Repository $store): array
    {
        $value = $store->get($this->key(), []);
        $value = is_array($value) ? $value : [];
        $titleIds = [];
        $storedTitleIds = $value['title_ids'] ?? [];

        if (is_array($storedTitleIds)) {
            foreach ($storedTitleIds as $id => $generation) {
                if ((! is_int($id) && ! ctype_digit($id))
                    || ! is_int($generation)
                    || (int) $id < 1
                    || $generation < 1) {
                    continue;
                }

                $titleIds[(int) $id] = $generation;

                if (count($titleIds) >= $this->requestTitleLimit()) {
                    break;
                }
            }
        }

        return [
            'generation' => max(0, (int) ($value['generation'] ?? 0)),
            'pending_generation' => max(0, (int) ($value['pending_generation'] ?? 0)),
            'refresh_generation' => max(0, (int) ($value['refresh_generation'] ?? 0)),
            'requested_at' => max(0, (int) ($value['requested_at'] ?? 0)),
            'title_ids' => $titleIds,
        ];
    }

    /** @param array{generation: int, pending_generation: int, refresh_generation: int, requested_at: int, title_ids: array<int, int>} $state */
    private function write(Repository $store, array $state): void
    {
        $store->put(
            $this->key(),
            $state,
            max(60, (int) config('cache-architecture.warming.request_retention_seconds', 604_800)),
        );
    }

    /** @param array{pending_generation: int, refresh_generation: int, title_ids: array<int, int>} $state */
    private function pending(array $state): bool
    {
        return $state['pending_generation'] > 0
            || $state['refresh_generation'] > 0
            || $state['title_ids'] !== [];
    }

    /** @param Closure(Repository): mixed $callback */
    private function synchronized(Closure $callback): mixed
    {
        $repository = Cache::store($this->store());

        if (! $repository instanceof Repository) {
            throw new LogicException('Cache store прогрева должен использовать Laravel cache repository.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Cache store прогрева должен поддерживать atomic locks.');
        }

        $lock = $store->lock(
            $this->keys->lock($this->key()),
            max(1, (int) config('cache-architecture.warming.request_lock_seconds', 10)),
        );

        return $lock->block(
            max(1, (int) config('cache-architecture.warming.request_lock_wait_seconds', 2)),
            fn (): mixed => $callback($repository),
        );
    }

    private function requestTitleLimit(): int
    {
        return max(1, (int) config('cache-architecture.warming.request_title_limit', 5_000));
    }

    private function key(): string
    {
        return $this->keys->data(CacheDomain::Operational, 'catalog-cache-warm-request', [], 1);
    }

    private function store(): string
    {
        return (string) config('cache-architecture.stores.locks', 'redis-locks');
    }
}
