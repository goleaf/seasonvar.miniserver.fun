<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\CatalogTitleRefreshState;
use App\Enums\SeasonvarImportStatus;
use App\Jobs\RefreshSeasonvarCatalogTitle;
use App\Models\CatalogTitle;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CatalogTitleRefreshCoordinator
{
    public function __construct(
        private readonly CatalogTitleRefreshStateStore $states,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function request(CatalogTitle $catalogTitle): CatalogTitleRefreshState
    {
        if (trim((string) $catalogTitle->source_url) === '') {
            return new CatalogTitleRefreshState;
        }

        try {
            $lock = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'))->lock(
                $this->states->dispatchLockKey($catalogTitle->id),
                max(1, (int) config('seasonvar.title_refresh.dispatch_lock_seconds', 10)),
            );

            if (! $lock->get()) {
                return $this->states->read($catalogTitle->id);
            }

            try {
                $state = $this->states->read($catalogTitle->id);

                if ($state->isActive() || $state->isFresh((int) config('seasonvar.title_refresh.fresh_minutes', 15))) {
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
}
