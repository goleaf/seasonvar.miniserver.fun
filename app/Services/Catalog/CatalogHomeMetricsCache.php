<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Catalog\Queries\CatalogHomeMetricsQuery;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\TieredCache;

final class CatalogHomeMetricsCache
{
    private const VERSION_SCOPE = 'metrics';

    public function __construct(
        private readonly CatalogHomeMetricsQuery $query,
        private readonly TieredCache $cache,
        private readonly CacheTtlPolicy $ttl,
        private readonly CacheVersionRegistry $versions,
    ) {}

    /**
     * @return array{titles: int, episodes: int, videos: int}
     */
    public function metrics(): array
    {
        return $this->read(refresh: false);
    }

    /** @return array{titles: int, episodes: int, videos: int} */
    public function refresh(): array
    {
        return $this->read(refresh: true);
    }

    /** @return array{titles: int, episodes: int, videos: int} */
    private function read(bool $refresh): array
    {
        $arguments = [
            CacheDomain::Homepage,
            'metrics',
            ['audience' => 'public', 'locale' => app()->getLocale()],
            $this->ttl->for(CacheDomain::CatalogStats),
            fn (): array => $this->query->handle(),
            false,
            self::VERSION_SCOPE,
        ];
        $result = $refresh
            ? $this->cache->refresh(...$arguments)
            : $this->cache->remember(...$arguments);

        return is_array($result->value) ? $result->value : ['titles' => 0, 'episodes' => 0, 'videos' => 0];
    }

    public function forget(): void
    {
        $this->versions->bump(CacheDomain::Homepage, self::VERSION_SCOPE);
    }
}
