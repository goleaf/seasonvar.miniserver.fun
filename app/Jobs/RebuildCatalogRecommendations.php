<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
use App\Services\Seasonvar\SeasonvarImportActivity;
use App\Services\Seasonvar\SeasonvarImportMaintenancePipeline;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class RebuildCatalogRecommendations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $timeout;

    public int $uniqueFor;

    public readonly int $retryUntilTimestamp;

    public function __construct()
    {
        $this->timeout = max(60, (int) config('seasonvar.recommendations.worker_timeout', 840));
        $this->uniqueFor = max(60, (int) config('seasonvar.recommendations.unique_seconds', 172_800));
        $this->retryUntilTimestamp = now()->addDays(2)->getTimestamp();
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
        $this->afterCommit();
    }

    public function handle(
        CatalogTitleRecommendationBuilder $recommendations,
        CatalogCacheWarmRequestStore $warmRequests,
        SeasonvarImportActivity $imports,
        SeasonvarImportMaintenancePipeline $maintenance,
    ): void {
        if ($imports->active()) {
            Log::info('Полный пересчёт рекомендаций ожидает завершения импорта.', [
                'job' => $this->uniqueId(),
            ]);
            $this->release(max(30, (int) config('seasonvar.queue.finalizer_delay_seconds', 60)));

            return;
        }

        $result = $recommendations->rebuildDirty(allowFullRebuild: true);

        if (($result['deferred'] ?? false) === true) {
            throw new RuntimeException('Полный пересчёт рекомендаций неожиданно отложен.');
        }

        if (($result['activated'] ?? false) !== true || ($result['gate_passed'] ?? false) !== true) {
            throw new RuntimeException('Полный пересчёт рекомендаций не прошёл проверку активации.');
        }

        $maintenance->pruneRecommendationSignals($result, null);

        $warmRequests->request(refresh: true);
        WarmCatalogCaches::dispatch(true)
            ->onConnection((string) config('cache-architecture.warming.connection', 'redis'))
            ->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm-v2'));

        Log::info('Полный пересчёт рекомендаций активирован.', [
            'job' => $this->uniqueId(),
            'build_id' => (int) ($result['build_id'] ?? 0),
        ]);
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

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->retryUntilTimestamp);
    }

    public function uniqueId(): string
    {
        return 'catalog-recommendations-full-v6';
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Полный пересчёт рекомендаций завершился ошибкой.', [
            'job' => $this->uniqueId(),
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
