<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
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
use RuntimeException;
use Throwable;

final class RebuildCatalogRecommendationsAfterCollectionSync implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public function __construct()
    {
        $this->timeout = max(60, (int) config(
            'catalog-collection-imports.hdrezka.recommendation_rebuild.timeout',
            900,
        ));
        $this->uniqueFor = max(60, (int) config(
            'catalog-collection-imports.hdrezka.recommendation_rebuild.unique_seconds',
            21_600,
        ));
        $this->onConnection((string) config(
            'catalog-collection-imports.hdrezka.recommendation_rebuild.connection',
            config('seasonvar.queue.connection', 'redis'),
        ));
        $this->onQueue((string) config(
            'catalog-collection-imports.hdrezka.recommendation_rebuild.queue',
            config('seasonvar.queue.queue', 'seasonvar-import'),
        ));
        $this->afterCommit();
    }

    public function handle(
        CatalogTitleRecommendationBuilder $recommendations,
        CatalogCacheWarmRequestStore $warmRequests,
        SeasonvarImportActivity $imports,
    ): void {
        if ($imports->active()) {
            Log::info('Перестроение рекомендаций отложено до активного импортного pipeline.');

            return;
        }

        $result = $recommendations->rebuildDirty(allowFullRebuild: false);

        if (($result['deferred'] ?? false) === true) {
            Log::info('Полное перестроение рекомендаций отложено до завершения импорта.', [
                'dirty_titles' => (int) ($result['dirty_titles'] ?? 0),
                'scope_fallback_reason' => $result['scope_fallback_reason'] ?? null,
            ]);

            return;
        }

        if (($result['activated'] ?? true) !== true || ($result['gate_passed'] ?? true) !== true) {
            throw new RuntimeException('Перестроение рекомендаций не прошло проверку активации.');
        }

        $warmRequests->request(refresh: true);
        WarmCatalogCaches::dispatch(true)
            ->onConnection((string) config('cache-architecture.warming.connection', 'redis'))
            ->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm-v2'));
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog-recommendation-rebuild-v6'))
                ->expireAfter($this->timeout + 60)
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
        return 'catalog-recommendations-after-collection-sync';
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config(
            'catalog-collection-imports.hdrezka.lock_store',
            'redis-locks',
        ));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Перестроение рекомендаций после синхронизации подборок завершилось ошибкой.', [
            'job' => $this->uniqueId(),
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
