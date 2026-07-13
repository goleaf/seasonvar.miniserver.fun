<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\CatalogTitleRefreshState;
use App\Enums\SeasonvarImportStatus;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CatalogTitleRefreshStateStore
{
    public function __construct(private readonly CacheKeyFactory $keys) {}

    public function read(int $catalogTitleId): CatalogTitleRefreshState
    {
        try {
            $state = $this->store()->get($this->key($catalogTitleId));

            return is_array($state) ? CatalogTitleRefreshState::fromArray($state) : new CatalogTitleRefreshState;
        } catch (Throwable $exception) {
            report($exception);

            return new CatalogTitleRefreshState;
        }
    }

    public function queued(int $catalogTitleId): CatalogTitleRefreshState
    {
        $now = now();
        $state = new CatalogTitleRefreshState(
            status: SeasonvarImportStatus::Queued,
            queuedAt: $now,
            activeUntil: $now->copy()->addSeconds($this->activeSeconds()),
        );

        return $this->write($catalogTitleId, $state);
    }

    public function running(int $catalogTitleId, ?int $importRunId = null): CatalogTitleRefreshState
    {
        $previous = $this->read($catalogTitleId);
        $now = now();
        $state = new CatalogTitleRefreshState(
            status: SeasonvarImportStatus::Running,
            queuedAt: $previous->queuedAt ?? $now,
            startedAt: $now,
            activeUntil: $now->copy()->addSeconds($this->activeSeconds()),
            importRunId: $importRunId,
        );

        return $this->write($catalogTitleId, $state);
    }

    public function completed(int $catalogTitleId, ?int $importRunId = null): CatalogTitleRefreshState
    {
        $previous = $this->read($catalogTitleId);
        $state = new CatalogTitleRefreshState(
            status: SeasonvarImportStatus::Completed,
            queuedAt: $previous->queuedAt,
            startedAt: $previous->startedAt,
            completedAt: now(),
            importRunId: $importRunId,
        );

        return $this->write($catalogTitleId, $state);
    }

    public function partial(int $catalogTitleId, ?int $importRunId = null): CatalogTitleRefreshState
    {
        $previous = $this->read($catalogTitleId);
        $state = new CatalogTitleRefreshState(
            status: SeasonvarImportStatus::Partial,
            queuedAt: $previous->queuedAt,
            startedAt: $previous->startedAt,
            completedAt: now(),
            importRunId: $importRunId,
        );

        return $this->write($catalogTitleId, $state);
    }

    public function failed(int $catalogTitleId): CatalogTitleRefreshState
    {
        $previous = $this->read($catalogTitleId);
        $state = new CatalogTitleRefreshState(
            status: SeasonvarImportStatus::Failed,
            queuedAt: $previous->queuedAt,
            startedAt: $previous->startedAt,
            failedAt: now(),
        );

        return $this->write($catalogTitleId, $state);
    }

    public function forget(int $catalogTitleId): void
    {
        try {
            $this->store()->forget($this->key($catalogTitleId));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function dispatchLockKey(int $catalogTitleId): string
    {
        return $this->keys->lock($this->key($catalogTitleId));
    }

    private function write(int $catalogTitleId, CatalogTitleRefreshState $state): CatalogTitleRefreshState
    {
        try {
            $this->store()->put(
                $this->key($catalogTitleId),
                $state->toArray(),
                max(60, (int) config('seasonvar.title_refresh.state_ttl_seconds', 86_400)),
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return $state;
    }

    private function key(int $catalogTitleId): string
    {
        return $this->keys->data(
            CacheDomain::Operational,
            'catalog-title-refresh',
            ['catalog_title_id' => $catalogTitleId],
            1,
        );
    }

    private function store(): Repository
    {
        return Cache::store((string) config('cache-architecture.stores.domain', 'redis-domain'));
    }

    private function activeSeconds(): int
    {
        return max(60, (int) config('seasonvar.title_refresh.active_seconds', 21_900));
    }
}
