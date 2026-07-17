<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Jobs\WarmCatalogCaches;
use App\Services\Collections\CatalogCollectionCacheInvalidator;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTelemetry;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CatalogCacheInvalidator
{
    private const PUBLIC_DOMAINS = [
        CacheDomain::Homepage,
        CacheDomain::CatalogPages,
        CacheDomain::CatalogFacets,
        CacheDomain::CatalogStats,
        CacheDomain::Api,
        CacheDomain::Sitemap,
        CacheDomain::Recommendations,
        CacheDomain::SearchSuggestions,
        CacheDomain::Tags,
        CacheDomain::Collections,
        CacheDomain::ReleaseCalendar,
    ];

    public function __construct(
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
        private readonly CatalogCacheWarmRequestStore $warmRequests,
        private readonly CatalogCollectionCacheInvalidator $collections,
    ) {}

    /** @param iterable<int, int|string> $titleIds */
    public function catalogChanged(iterable $titleIds = []): void
    {
        $normalizedIds = collect($titleIds)
            ->filter(fn (int|string $id): bool => is_int($id) || ctype_digit($id))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(1_000)
            ->values()
            ->all();
        $invalidate = fn () => $this->invalidateNow($normalizedIds);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }

    public function importedTitleChanged(int $titleId): void
    {
        if ($titleId < 1) {
            return;
        }

        $invalidate = fn () => $this->invalidateImportedTitleNow($titleId);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }

    public function titlePlaybackMetadataChanged(int $titleId): void
    {
        if ($titleId < 1) {
            return;
        }

        $invalidate = fn () => $this->invalidateTitlePlaybackMetadataNow($titleId);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }

    /** @param list<int> $titleIds */
    private function invalidateNow(array $titleIds): void
    {
        foreach (self::PUBLIC_DOMAINS as $domain) {
            $this->versions->bump($domain);
            $this->telemetry->increment($domain, 'invalidation');
        }

        foreach ($titleIds as $titleId) {
            $this->versions->bump(CacheDomain::TitleDetail, 'title:'.$titleId);
        }

        if ($titleIds === []) {
            $this->versions->bump(CacheDomain::TitleDetail);
        }

        $this->dispatchWarm($titleIds, refresh: $titleIds === []);
    }

    private function invalidateImportedTitleNow(int $titleId): void
    {
        $this->versions->bump(CacheDomain::TitleDetail, 'title:'.$titleId);
        $this->telemetry->increment(CacheDomain::TitleDetail, 'invalidation');
        $this->collections->titleChanged($titleId);
        $this->dispatchWarm([$titleId], refresh: false);
    }

    private function invalidateTitlePlaybackMetadataNow(int $titleId): void
    {
        $this->versions->bump(CacheDomain::TitleDetail, 'title:'.$titleId);
        $this->telemetry->increment(CacheDomain::TitleDetail, 'playback-metadata-invalidation');
    }

    /** @param list<int> $titleIds */
    private function dispatchWarm(array $titleIds, bool $refresh): void
    {
        if (! (bool) config('cache-architecture.warming.enabled', true)) {
            return;
        }

        $job = (new WarmCatalogCaches)
            ->onConnection((string) config('cache-architecture.warming.connection', 'redis'))
            ->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm-v2'))
            ->afterCommit();

        try {
            $this->warmRequests->request($titleIds, $refresh);
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            $this->telemetry->increment(CacheDomain::Operational, 'warming-dispatch-failure');
            Log::warning('Инвалидация каталога завершена, но отложенный прогрев не поставлен в очередь.', [
                'exception' => $exception::class,
            ]);
        }
    }
}
