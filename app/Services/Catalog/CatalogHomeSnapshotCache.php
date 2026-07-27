<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Catalog\Queries\CatalogHomeSnapshotQuery;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\TieredCache;

final class CatalogHomeSnapshotCache
{
    public function __construct(
        private readonly CatalogHomeSnapshotQuery $query,
        private readonly TieredCache $cache,
        private readonly CacheTtlPolicy $ttl,
    ) {}

    /**
     * @return array{
     *     latest_title_ids: list<int>,
     *     latest_title_updates: list<array{id: int, added_at: string}>,
     *     featured_title_ids: list<int>,
     *     video_title_ids: list<int>,
     *     latest_media_ids: list<int>,
     *     year_buckets: list<array{year: int, titles_count: int}>,
     *     subtitle_tag: array<string, mixed>|null
     * }
     */
    public function snapshot(): array
    {
        return $this->read(refresh: false);
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        return $this->read(refresh: true);
    }

    /** @return array<string, mixed> */
    private function read(bool $refresh): array
    {
        $arguments = [
            CacheDomain::Homepage,
            'content-index-v3',
            ['audience' => 'public', 'locale' => app()->getLocale(), 'year' => (int) now()->format('Y')],
            $this->ttl->for(CacheDomain::Homepage),
            fn (): array => $this->query->handle(),
        ];
        $result = $refresh
            ? $this->cache->refresh(...$arguments)
            : $this->cache->remember(...$arguments);

        return is_array($result->value) ? $result->value : $this->emptySnapshot();
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(): array
    {
        return [
            'latest_title_ids' => [],
            'latest_title_updates' => [],
            'featured_title_ids' => [],
            'video_title_ids' => [],
            'latest_media_ids' => [],
            'year_buckets' => [],
            'subtitle_tag' => null,
        ];
    }
}
