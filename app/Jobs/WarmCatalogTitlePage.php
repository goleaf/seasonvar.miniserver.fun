<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\PublicCacheWarmTarget;
use App\Models\CatalogTitle;
use App\Services\Catalog\PublicPageCacheWarmer;
use App\Services\Seasonvar\SeasonvarImportActivity;
use App\Support\Cache\CacheEntryState;
use App\Support\Cache\PublicPageCachePolicy;
use App\Support\Cache\TieredCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class WarmCatalogTitlePage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(public readonly int $titleId)
    {
        $requestTimeout = max(1, (int) config('cache-architecture.page_cache.warm_timeout_seconds', 10));
        $requestAttempts = max(1, (int) config('cache-architecture.page_cache.warm_retry_times', 2));
        $this->timeout = max(30, min(120, ($requestTimeout * $requestAttempts) + 10));
        $this->uniqueFor = max(60, (int) config('cache-architecture.warming.visible_titles.unique_seconds', 600));
        $this->onConnection((string) config('cache-architecture.warming.connection', 'redis'));
        $this->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm-v2'));
        $this->afterCommit();
    }

    public function handle(
        SeasonvarImportActivity $imports,
        PublicPageCachePolicy $policy,
        TieredCache $cache,
        PublicPageCacheWarmer $warmer,
    ): void {
        if ($imports->active()) {
            $this->release(max(30, (int) config('cache-architecture.warming.visible_titles.import_pause_seconds', 300)));

            return;
        }

        $title = CatalogTitle::query()->availableTo(null)->whereKey($this->titleId)->first();

        if ($title === null || ($context = $policy->canonicalTitleContext($title)) === null) {
            return;
        }

        $state = $cache->state(
            $context->domain,
            'response-html',
            $context->dimensions,
            $context->versionScope,
        );

        if ($state === CacheEntryState::Unavailable) {
            $this->release(max(15, (int) config('cache-architecture.warming.visible_titles.unavailable_pause_seconds', 60)));

            return;
        }

        if (! $state->needsWarm()) {
            return;
        }

        $result = $warmer->warmTargets([
            new PublicCacheWarmTarget(route('titles.show', ['catalogTitle' => $title->slug], false)),
        ]);

        if ($result['failed'] > 0 || $result['succeeded'] !== 1) {
            throw new RuntimeException('Фоновый прогрев страницы тайтла не завершился успешно.');
        }
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
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
        return 'catalog-title-page:'.$this->titleId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('cache-architecture.stores.locks', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Фоновый прогрев страницы тайтла завершился ошибкой.', [
            'title_id' => $this->titleId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
