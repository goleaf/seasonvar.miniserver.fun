<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogCollectionCategorySuggestion;
use App\DTOs\CatalogCollectionClassificationSummary;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class CatalogCollectionClassificationQuery
{
    private const PAGE_NAME = 'collectionCategoryClassificationPage';

    public function __construct(
        private readonly CatalogCollectionSchema $schema,
        private readonly CatalogSearchNormalizer $search,
        private readonly CatalogCollectionCategorySuggestionService $suggestions,
    ) {}

    public function summary(): CatalogCollectionClassificationSummary
    {
        if (! $this->schema->available()) {
            return new CatalogCollectionClassificationSummary(0, 0, 0, 0, 0.0);
        }

        $row = CatalogCollection::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(
                'SUM(CASE WHEN catalog_collection_category_id IS NOT NULL THEN 1 ELSE 0 END) AS categorized',
            )
            ->selectRaw(
                'SUM(CASE WHEN catalog_collection_category_id IS NULL THEN 1 ELSE 0 END) AS uncategorized',
            )
            ->selectRaw(
                'SUM(CASE WHEN catalog_collection_category_id IS NULL AND visibility = ? AND moderation_status = ? THEN 1 ELSE 0 END) AS public_uncategorized',
                [
                    CatalogCollectionVisibility::Public->value,
                    CatalogCollectionModerationStatus::Approved->value,
                ],
            )
            ->first();

        $total = max(0, (int) ($row?->total ?? 0));
        $categorized = max(0, (int) ($row?->categorized ?? 0));

        return new CatalogCollectionClassificationSummary(
            total: $total,
            categorized: $categorized,
            uncategorized: max(0, (int) ($row?->uncategorized ?? 0)),
            publicUncategorized: max(0, (int) ($row?->public_uncategorized ?? 0)),
            completionPercentage: $total > 0
                ? round(($categorized / $total) * 100, 1)
                : 0.0,
        );
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function paginateUncategorized(
        string $search = '',
        string $visibility = '',
        string $type = '',
        int $perPage = 20,
        string $moderationStatus = '',
    ): LengthAwarePaginator {
        $perPage = max(10, min(50, $perPage));

        if (! $this->schema->available()) {
            return $this->emptyPaginator($perPage);
        }

        $search = $this->search->display(mb_substr($search, 0, 100));
        $searchPattern = $this->containsPattern($search);
        $visibilityFilter = CatalogCollectionVisibility::tryFrom($visibility);
        $typeFilter = CatalogCollectionType::tryFrom($type);
        $moderationFilter = CatalogCollectionModerationStatus::tryFrom($moderationStatus);
        $query = CatalogCollection::query()
            ->select([
                'id',
                'public_id',
                'owner_id',
                'name',
                'description',
                'slug',
                'type',
                'visibility',
                'moderation_status',
                'content_version',
                'updated_at',
            ])
            ->whereNull('catalog_collection_category_id')
            ->when(
                $visibilityFilter !== null,
                fn (Builder $query): Builder => $query->where('visibility', $visibilityFilter->value),
            )
            ->when(
                $typeFilter !== null,
                fn (Builder $query): Builder => $query->where('type', $typeFilter->value),
            )
            ->when(
                $moderationFilter !== null,
                fn (Builder $query): Builder => $query->where(
                    'moderation_status',
                    $moderationFilter->value,
                ),
            )
            ->when($search !== '', function (Builder $query) use ($searchPattern): void {
                $query->where(function (Builder $query) use ($searchPattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereHas('translations', fn (Builder $translations): Builder => $translations
                            ->whereIn('locale', $this->searchLocales())
                            ->where(function (Builder $translations) use ($searchPattern): void {
                                $translations
                                    ->whereRaw("name LIKE ? ESCAPE '!'", [$searchPattern])
                                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$searchPattern]);
                            }))
                        ->orWhereHas('owner', fn (Builder $owner): Builder => $owner
                            ->whereRaw("name LIKE ? ESCAPE '!'", [$searchPattern]));
                });
            });

        $paginator = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: self::PAGE_NAME);

        $this->loadEvidence($paginator);

        return $paginator;
    }

    /**
     * @param  LengthAwarePaginator<int, CatalogCollection>  $paginator
     * @param  Collection<int, CatalogCollectionCategory>  $activeTree
     * @return array<string, CatalogCollectionCategorySuggestion>
     */
    public function suggestionsFor(
        LengthAwarePaginator $paginator,
        Collection $activeTree,
    ): array {
        return $paginator->getCollection()
            ->mapWithKeys(fn (CatalogCollection $collection): array => [
                (string) $collection->public_id => $this->suggestions->suggest(
                    $collection,
                    $activeTree,
                ),
            ])
            ->all();
    }

    /** @param LengthAwarePaginator<int, CatalogCollection> $paginator */
    private function loadEvidence(LengthAwarePaginator $paginator): void
    {
        /** @var EloquentCollection<int, CatalogCollection> $collections */
        $collections = $paginator->getCollection();

        if ($collections->isEmpty()) {
            return;
        }

        $relations = [
            'owner:id,public_id,name',
            'translations' => fn (HasMany $query): HasMany => $query
                ->select([
                    'id',
                    'catalog_collection_id',
                    'locale',
                    'name',
                    'description',
                ])
                ->whereIn('locale', $this->searchLocales()),
            'items' => fn (HasMany $query): HasMany => $query
                ->select([
                    'id',
                    'catalog_collection_id',
                    'catalog_title_id',
                    'position',
                ])
                ->limit(50),
            'items.catalogTitle:id,title,original_title,type,year',
            'items.catalogTitle.genres:id,name,slug',
            'items.catalogTitle.countries:id,name,slug',
            'items.catalogTitle.networks:id,name,slug',
            'items.catalogTitle.studios:id,name,slug',
        ];

        if ($this->schema->sourceSyncAvailable()) {
            $relations['sourceRecord'] = fn ($query) => $query->select([
                'id',
                'catalog_collection_id',
                'provider',
                'remote_name',
            ]);
        }

        $collections->load($relations);
        $collectionIds = $collections->modelKeys();
        $itemCounts = CatalogCollectionItem::query()
            ->select('catalog_collection_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->whereIn('catalog_collection_id', $collectionIds)
            ->groupBy('catalog_collection_id')
            ->pluck('aggregate', 'catalog_collection_id');

        foreach ($collections as $collection) {
            $collection->setAttribute(
                'total_items_count',
                max(0, (int) ($itemCounts[$collection->id] ?? 0)),
            );
        }
    }

    /** @return list<string> */
    private function searchLocales(): array
    {
        return array_values(array_unique([
            app()->currentLocale(),
            (string) config('catalog-collections.default_locale', 'ru'),
        ]));
    }

    private function containsPattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    private function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            [],
            0,
            $perPage,
            LengthAwarePaginator::resolveCurrentPage(self::PAGE_NAME),
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => self::PAGE_NAME,
            ],
        );
    }
}
