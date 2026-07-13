<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\CacheWarmingState;
use App\Services\Catalog\CatalogCacheWarmer;
use DateTimeInterface;
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
use Throwable;

final class WarmCatalogCaches implements ShouldBeUnique, ShouldQueue
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
        $this->timeout = max(30, (int) config('cache-architecture.warming.timeout', 300));
        $this->uniqueFor = max(30, (int) config('cache-architecture.warming.unique_seconds', 300));
        $this->onConnection((string) config('cache-architecture.warming.connection', 'redis'));
        $this->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm'));
        $this->afterCommit();
    }

    public function handle(CatalogCacheWarmer $warmer): void
    {
        $warmer->warmCritical($this->refresh);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog-cache-warm'))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(30),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function uniqueId(): string
    {
        return 'catalog-critical-cache-warm';
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
