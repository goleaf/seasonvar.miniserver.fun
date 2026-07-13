<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\TieredCache;
use Closure;

final class CatalogFacetSnapshotCache
{
    public function __construct(
        private readonly TieredCache $cache,
        private readonly CacheTtlPolicy $ttl,
        private readonly CacheVersionRegistry $versions,
    ) {}

    /**
     * @param  array<string, mixed>  $dimensions
     * @param  Closure(): list<array<string, mixed>>  $rebuild
     * @return list<array<string, mixed>>
     */
    public function remember(string $resource, array $dimensions, Closure $rebuild, bool $refresh = false): array
    {
        $arguments = [
            CacheDomain::CatalogFacets,
            $resource,
            $dimensions,
            $this->ttl->for(CacheDomain::CatalogFacets),
            $rebuild,
        ];
        $result = $refresh
            ? $this->cache->refresh(...$arguments)
            : $this->cache->remember(...$arguments);

        return is_array($result->value) ? array_values($result->value) : [];
    }

    public function forget(): void
    {
        $this->versions->bump(CacheDomain::CatalogFacets);
    }
}
