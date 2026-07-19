<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\PublicCatalogWarmStateStore;
use App\Services\Catalog\PublicCatalogWarmTargetSource;
use App\Services\Catalog\PublicPageCacheWarmer;
use App\Services\Seasonvar\SeasonvarImportActivity;
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
use Throwable;

final class WarmPublicCatalogCaches implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(
        public readonly string $generation,
        public readonly bool $refresh = false,
    ) {
        $this->timeout = max(30, (int) config('cache-architecture.warming.timeout', 600));
        $this->uniqueFor = max(30, (int) config('cache-architecture.warming.unique_seconds', 604_800));
        $this->onConnection((string) config('cache-architecture.warming.connection', 'redis'));
        $this->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm-v2'));
        $this->afterCommit();
    }

    public function handle(
        PublicCatalogWarmStateStore $states,
        PublicCatalogWarmTargetSource $targets,
        PublicPageCacheWarmer $warmer,
        SeasonvarImportActivity $imports,
    ): void {
        if ($imports->active()) {
            $this->release(max(30, (int) config(
                'cache-architecture.warming.full_import_pause_seconds',
                300,
            )));

            return;
        }

        $state = $states->markRunning($this->generation);

        if ($state === null || ! in_array($state['status'], ['queued', 'running', 'failed'], true)) {
            return;
        }

        $batch = $targets->batch(
            is_array($state['cursor']) ? $state['cursor'] : null,
            $this->batchLimit(),
        );
        $result = $warmer->warmTargets($batch->targets);
        $updated = $states->advance($this->generation, $batch, $result);

        if ($updated !== null && ! $batch->completed) {
            self::dispatch($this->generation, (bool) $updated['refresh']);
        }
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog-cache-warm-all-public-v1'))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(60),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'catalog-all-public-cache-warm:'.$this->generation;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('cache-architecture.stores.locks', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        app(PublicCatalogWarmStateStore::class)->failed($this->generation, $exception);

        Log::error('Полный прогрев публичного каталога завершился ошибкой.', [
            'generation' => $this->generation,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }

    private function batchLimit(): int
    {
        $configured = max(1, (int) config('cache-architecture.warming.full_batch_url_limit', 25));
        $budget = max(1, (int) config('cache-architecture.warming.full_job_budget_seconds', 180));
        $requestTimeout = max(1, (int) config('cache-architecture.page_cache.warm_timeout_seconds', 10));

        return min($configured, max(1, intdiv($budget, $requestTimeout)));
    }
}
