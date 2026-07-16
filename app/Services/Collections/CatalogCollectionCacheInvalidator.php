<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
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
            : [], recommendationsChanged: $this->recommendationsMayChange($collection));
    }

    /** @param iterable<CatalogCollection> $collections */
    public function changedMany(iterable $collections): void
    {
        $ids = [];
        $recommendationsChanged = false;

        foreach ($collections as $collection) {
            if (is_int($collection->getKey())) {
                $ids[] = $collection->getKey();
            }

            $recommendationsChanged = $recommendationsChanged
                || $this->recommendationsMayChange($collection);
        }

        if ($ids === []) {
            return;
        }

        $this->changedIds($ids, recommendationsChanged: $recommendationsChanged);
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

        $scopeOverflow = count($collectionIds) > self::MAXIMUM_TITLE_COLLECTION_SCOPES;
        $recommendationsChanged = $scopeOverflow || CatalogCollection::query()
            ->whereKey($collectionIds)
            ->where('type', CatalogCollectionType::Editorial->value)
            ->where('visibility', CatalogCollectionVisibility::Public->value)
            ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value)
            ->where('is_featured', true)
            ->whereNotNull('published_at')
            ->exists();

        $this->changedIds(
            $scopeOverflow ? [] : $collectionIds,
            includeTitleDetail: false,
            recommendationsChanged: $recommendationsChanged,
        );
    }

    /** @param list<int> $collectionIds */
    private function changedIds(
        array $collectionIds,
        bool $includeTitleDetail = true,
        bool $recommendationsChanged = true,
    ): void {
        $collectionIds = array_values(array_unique($collectionIds));
        $invalidate = function () use ($collectionIds, $includeTitleDetail, $recommendationsChanged): void {
            $this->versions->bump(CacheDomain::Collections);
            $this->versions->bump(CacheDomain::Homepage);
            $this->versions->bump(CacheDomain::Sitemap);
            $this->versions->bump(CacheDomain::Api);
            $this->versions->bump(CacheDomain::SearchSuggestions);

            if ($recommendationsChanged) {
                $this->versions->bump(CacheDomain::Recommendations);
            }

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

    private function recommendationsMayChange(?CatalogCollection $collection): bool
    {
        if ($collection === null) {
            return true;
        }

        $current = $collection->getAttributes();

        if (! $this->hasRecommendationEligibilityAttributes($current)) {
            return true;
        }

        if ($this->isRecommendationEligible($current)) {
            return true;
        }

        $previous = $collection->getPrevious();

        return $previous !== []
            && $this->isRecommendationEligible(array_replace($current, $previous));
    }

    /** @param array<string, mixed> $attributes */
    private function hasRecommendationEligibilityAttributes(array $attributes): bool
    {
        return collect([
            'type',
            'visibility',
            'moderation_status',
            'is_featured',
            'published_at',
        ])->every(fn (string $attribute): bool => array_key_exists($attribute, $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function isRecommendationEligible(array $attributes): bool
    {
        return ($attributes['type'] ?? null) === CatalogCollectionType::Editorial->value
            && ($attributes['visibility'] ?? null) === CatalogCollectionVisibility::Public->value
            && ($attributes['moderation_status'] ?? null) === CatalogCollectionModerationStatus::Approved->value
            && (bool) ($attributes['is_featured'] ?? false)
            && ($attributes['published_at'] ?? null) !== null
            && ($attributes['deleted_at'] ?? null) === null;
    }
}
