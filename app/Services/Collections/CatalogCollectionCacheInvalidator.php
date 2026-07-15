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
        $invalidate = function () use ($collection): void {
            $this->versions->bump(CacheDomain::Collections);
            $this->versions->bump(CacheDomain::Homepage);
            $this->versions->bump(CacheDomain::Sitemap);
            $this->versions->bump(CacheDomain::TitleDetail);
            $this->versions->bump(CacheDomain::Recommendations);
            $this->versions->bump(CacheDomain::Api);

            if ($collection !== null && is_int($collection->getKey())) {
                $this->versions->bump(CacheDomain::Collections, 'collection:'.$collection->getKey());
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }
}
