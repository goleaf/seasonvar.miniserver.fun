<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Models\CatalogCollection;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\DB;

final class CatalogCollectionCacheInvalidator
{
    public function __construct(private readonly CacheVersionRegistry $versions) {}

    public function changed(?CatalogCollection $collection = null): void
    {
        $this->changedIds($collection !== null && is_int($collection->getKey())
            ? [$collection->getKey()]
            : []);
    }

    /** @param iterable<CatalogCollection> $collections */
    public function changedMany(iterable $collections): void
    {
        $ids = [];

        foreach ($collections as $collection) {
            if (is_int($collection->getKey())) {
                $ids[] = $collection->getKey();
            }
        }

        if ($ids === []) {
            return;
        }

        $this->changedIds($ids);
    }

    /** @param list<int> $collectionIds */
    private function changedIds(array $collectionIds): void
    {
        $collectionIds = array_values(array_unique($collectionIds));
        $invalidate = function () use ($collectionIds): void {
            $this->versions->bump(CacheDomain::Collections);
            $this->versions->bump(CacheDomain::Homepage);
            $this->versions->bump(CacheDomain::Sitemap);
            $this->versions->bump(CacheDomain::TitleDetail);
            $this->versions->bump(CacheDomain::Recommendations);
            $this->versions->bump(CacheDomain::Api);

            foreach ($collectionIds as $collectionId) {
                $this->versions->bump(CacheDomain::Collections, 'collection:'.$collectionId);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }
}
