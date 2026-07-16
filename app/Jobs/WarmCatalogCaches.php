<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\CatalogCacheWarmWork;
use App\Services\Catalog\CacheWarmingState;
use App\Services\Catalog\CatalogCacheWarmer;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final class WarmCatalogCaches implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(public readonly bool $refresh = false)
    {
        $this->timeout = max(30, (int) config('cache-architecture.warming.timeout', 600));
        $this->uniqueFor = max(30, (int) config('cache-architecture.warming.unique_seconds', 604_800));
        $this->onConnection((string) config('cache-architecture.warming.connection', 'redis'));
        $this->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm-v2'));
        $this->afterCommit();
    }

    public function handle(CatalogCacheWarmer $warmer, CatalogCacheWarmRequestStore $requests): void
    {
        $work = $requests->claim(
            max(1, (int) config('cache-architecture.warming.request_batch_title_limit', 250)),
        );

        if ($work === null) {
            return;
        }

        $titleLimit = $warmer->titleBatchLimit();

        if ($work->titleIds !== [] && $titleLimit < 1) {
            throw new LogicException('Лимит HTTP-прогрева не оставляет места для изменённых страниц тайтлов.');
        }

        if (count($work->titleIds) > $titleLimit) {
            $work = new CatalogCacheWarmWork(
                generation: $work->generation,
                refresh: $work->refresh,
                titleIds: array_slice($work->titleIds, 0, $titleLimit),
            );
        }

        $warmer->warmCritical($work->refresh, $work->titleIds);

        if ($requests->complete($work)) {
            self::dispatch();
        }
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog-cache-warm-v2'))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(30),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return 'catalog-critical-cache-warm-v2';
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('cache-architecture.stores.locks', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            app(CacheWarmingState::class)->failed($exception);
        }

        Log::error('Прогрев критических кэшей каталога завершился ошибкой.', [
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
