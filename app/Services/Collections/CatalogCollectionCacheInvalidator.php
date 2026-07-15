<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CatalogCollectionCacheInvalidator
{
    private const MAXIMUM_TITLE_COLLECTION_SCOPES = 1_000;

    public function __construct(
        private readonly CacheVersionRegistry $versions,
        private readonly CatalogCollectionSchema $schema,
    ) {}

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

    public function titleChanged(int $catalogTitleId): void
    {
        if ($catalogTitleId < 1 || ! $this->schema->available()) {
            return;
        }

        $collectionIds = CatalogCollectionItem::query()
            ->where('catalog_title_id', $catalogTitleId)
            ->whereHas('collection', fn (Builder $query): Builder => $query
                ->where('visibility', CatalogCollectionVisibility::Public->value)
                ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value))
            ->orderBy('catalog_collection_id')
            ->limit(self::MAXIMUM_TITLE_COLLECTION_SCOPES + 1)
            ->pluck('catalog_collection_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($collectionIds === []) {
            return;
        }

        $this->changedIds(
            count($collectionIds) > self::MAXIMUM_TITLE_COLLECTION_SCOPES ? [] : $collectionIds,
            includeTitleDetail: false,
        );
    }

    /** @param list<int> $collectionIds */
    private function changedIds(array $collectionIds, bool $includeTitleDetail = true): void
    {
        $collectionIds = array_values(array_unique($collectionIds));
        $invalidate = function () use ($collectionIds, $includeTitleDetail): void {
            $this->versions->bump(CacheDomain::Collections);
            $this->versions->bump(CacheDomain::Homepage);
            $this->versions->bump(CacheDomain::Sitemap);
            $this->versions->bump(CacheDomain::Recommendations);
            $this->versions->bump(CacheDomain::Api);

            if ($includeTitleDetail) {
                $this->versions->bump(CacheDomain::TitleDetail);
            }

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
