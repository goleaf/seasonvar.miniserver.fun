<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\TieredCache;
use Closure;

final readonly class TagSnapshotCache
{
    public function __construct(
        private TieredCache $cache,
        private CacheTtlPolicy $ttl,
    ) {}

    /**
     * @param  array<string, mixed>  $dimensions
     * @param  Closure(): array<mixed>  $rebuild
     * @return array<mixed>
     */
    public function remember(string $resource, array $dimensions, Closure $rebuild): array
    {
        $result = $this->cache->remember(
            CacheDomain::Tags,
            $resource,
            $dimensions,
            $this->ttl->for(CacheDomain::Tags),
            $rebuild,
        );

        return is_array($result->value) ? $result->value : [];
    }
}
