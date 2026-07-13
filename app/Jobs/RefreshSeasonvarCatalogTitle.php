<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CatalogTitle;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use App\Services\Seasonvar\SeasonvarUrl;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class RefreshSeasonvarCatalogTitle implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $timeout;

    public int $uniqueFor;

    private readonly int $retryUntilTimestamp;

    public function __construct(public readonly int $catalogTitleId)
    {
        $this->timeout = max(60, (int) config('seasonvar.queue.worker_timeout', 900));
        $this->uniqueFor = max(300, (int) config('seasonvar.title_refresh.active_seconds', 21_900));
        $this->retryUntilTimestamp = now()
            ->addSeconds(max(300, (int) config('seasonvar.queue.retry_window_seconds', 21_600)))
            ->getTimestamp();
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.title_refresh.queue', 'seasonvar-title-refresh'));
    }

    public function handle(
        SeasonvarImportTitleGroupDispatcher $groups,
        SeasonvarUrl $urls,
        CatalogTitleRefreshStateStore $states,
    ): void {
        $catalogTitle = CatalogTitle::query()->find($this->catalogTitleId);

        if ($catalogTitle === null) {
            $states->forget($this->catalogTitleId);

            return;
        }

        $url = $urls->normalize((string) $catalogTitle->source_url);

        if (! $urls->isAllowed($url)) {
            throw new InvalidArgumentException('Ссылка тайтла не принадлежит разрешенному каталогу Seasonvar.');
        }

        $group = $groups->start(
            $catalogTitle,
            (string) config('seasonvar.title_refresh.queue', 'seasonvar-title-refresh'),
        );
        $states->running($this->catalogTitleId, $group->seasonvar_import_run_id);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->setTimestamp($this->retryUntilTimestamp);
    }

    public function uniqueId(): string
    {
        return 'catalog-title-refresh:'.$this->catalogTitleId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        app(CatalogTitleRefreshStateStore::class)->failed($this->catalogTitleId);

        Log::error('Фоновое обновление страницы тайтла Seasonvar завершилось ошибкой.', [
            'catalog_title_id' => $this->catalogTitleId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
