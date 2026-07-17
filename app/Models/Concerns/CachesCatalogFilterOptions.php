<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Wddyousuf\AutoCache\Query\CachedBuilder;
use Wddyousuf\AutoCache\Traits\Cacheable;

trait CachesCatalogFilterOptions
{
    use Cacheable;

    /** @return CachedBuilder<static> */
    public static function cachedCatalogFilterOptions(): CachedBuilder
    {
        return static::query()
            ->cache()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(100);
    }

    /** @return array<int, CachedBuilder<static>> */
    public function cacheWarmupQueries(): array
    {
        return [static::cachedCatalogFilterOptions()];
    }
}
