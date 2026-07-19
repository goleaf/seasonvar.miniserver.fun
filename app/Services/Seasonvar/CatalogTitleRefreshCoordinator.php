<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\CatalogTitleRefreshState;
use App\Enums\SeasonvarImportStatus;
use App\Jobs\RefreshSeasonvarCatalogTitle;
use App\Models\CatalogTitle;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

final class CatalogTitleRefreshCoordinator
{
    public function __construct(
        private readonly CatalogTitleRefreshStateStore $states,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function shouldRequest(CatalogTitle $catalogTitle, CatalogTitleRefreshState $state): bool
    {
        return trim((string) $catalogTitle->source_url) !== ''
            && ! $state->isActive()
            && ! $state->isFresh((int) config('seasonvar.title_refresh.fresh_minutes', 15));
    }

    public function request(CatalogTitle $catalogTitle): CatalogTitleRefreshState
    {
        if (trim((string) $catalogTitle->source_url) === '') {
            return new CatalogTitleRefreshState;
        }

        try {
            $lock = $this->lockStore()->lock(
                $this->states->dispatchLockKey($catalogTitle->id),
                max(1, (int) config('seasonvar.title_refresh.dispatch_lock_seconds', 10)),
            );

            if (! $lock->get()) {
                return $this->states->read($catalogTitle->id);
            }

            try {
                $state = $this->states->read($catalogTitle->id);

                if (! $this->shouldRequest($catalogTitle, $state)) {
                    return $state;
                }

                $state = $this->states->queued($catalogTitle->id);

                try {
                    $this->dispatcher->dispatch((new RefreshSeasonvarCatalogTitle($catalogTitle->id))->afterCommit());
                } catch (Throwable $exception) {
                    report($exception);

                    return $this->states->failed($catalogTitle->id);
                }

                return $state;
            } finally {
                $lock->release();
            }
        } catch (Throwable $exception) {
            report($exception);

            return new CatalogTitleRefreshState(
                status: SeasonvarImportStatus::Failed,
                failedAt: now(),
            );
        }
    }

    private function lockStore(): Store&LockProvider
    {
        $repository = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('Seasonvar title refresh lock repository is unavailable.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Seasonvar title refresh cache store does not support atomic locks.');
        }

        return $store;
    }
}
