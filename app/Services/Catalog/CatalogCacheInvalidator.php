<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Jobs\WarmCatalogCaches;
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
    ];

    public function __construct(
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
        private readonly CatalogCacheWarmRequestStore $warmRequests,
    ) {}

    /** @param iterable<int, int|string> $titleIds */
    public function catalogChanged(iterable $titleIds = []): void
    {
        $normalizedIds = collect($titleIds)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
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

        if ((bool) config('cache-architecture.warming.enabled', true)) {
            $job = (new WarmCatalogCaches)
                ->onConnection((string) config('cache-architecture.warming.connection', 'redis'))
                ->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm'))
                ->afterCommit();

            try {
                $this->warmRequests->request($titleIds, refresh: $titleIds === []);
                Bus::dispatch($job);
            } catch (Throwable $exception) {
                $this->telemetry->increment(CacheDomain::Operational, 'warming-dispatch-failure');
                Log::warning('Инвалидация каталога завершена, но отложенный прогрев не поставлен в очередь.', [
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
