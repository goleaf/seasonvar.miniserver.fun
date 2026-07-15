<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\TieredCache;

final class CatalogHomeMetricsCache
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
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
            $this->ttl->for(CacheDomain::Homepage),
            fn (): array => $this->build(),
        ];
        $result = $refresh
            ? $this->cache->refresh(...$arguments)
            : $this->cache->remember(...$arguments);

        return is_array($result->value) ? $result->value : ['titles' => 0, 'episodes' => 0, 'videos' => 0];
    }

    public function forget(): void
    {
        $this->versions->bump(CacheDomain::Homepage);
    }

    /**
     * @return array{titles: int, episodes: int, videos: int}
     */
    private function build(): array
    {
        return [
            'titles' => $this->titles->visibleTo(null)->count(),
            'episodes' => Episode::query()
                ->published()
                ->whereIn('season_id', Season::query()
                    ->published()
                    ->select('id')
                    ->whereIn('catalog_title_id', $this->titles->visibleTo(null)->select('id')))
                ->count(),
            'videos' => LicensedMedia::query()
                ->published()
                ->forAvailableReleases(null)
                ->whereIn('catalog_title_id', $this->titles->visibleTo(null)->select('id'))
                ->count(),
        ];
    }
}
