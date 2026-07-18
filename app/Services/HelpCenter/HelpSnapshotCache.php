<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\TieredCache;
use Closure;

final readonly class HelpSnapshotCache
{
    public function __construct(private TieredCache $cache, private CacheTtlPolicy $ttl) {}

    /**
     * @param  array<string, mixed>  $dimensions
     * @param  Closure(): array<mixed>  $rebuild
     * @return array<mixed>
     */
    public function remember(string $resource, array $dimensions, Closure $rebuild): array
    {
        $result = $this->cache->remember(
            CacheDomain::HelpCenter,
            $resource,
            ['format' => 2, ...$dimensions],
            $this->ttl->for(CacheDomain::HelpCenter),
            $rebuild,
        );

        return is_array($result->value) ? $result->value : [];
    }

    /**
     * @template T
     *
     * @param  Closure(): list<T>  $rebuild
     * @param  Closure(T): array<string, mixed>  $dehydrate
     * @param  Closure(array<string, mixed>): T  $hydrate
     * @return list<T>
     */
    public function rememberList(
        string $resource,
        array $dimensions,
        Closure $rebuild,
        Closure $dehydrate,
        Closure $hydrate,
    ): array {
        $snapshots = $this->remember(
            $resource,
            $dimensions,
            static fn (): array => array_map($dehydrate, $rebuild()),
        );

        return array_values(array_map(
            $hydrate,
            array_filter($snapshots, 'is_array'),
        ));
    }
}
