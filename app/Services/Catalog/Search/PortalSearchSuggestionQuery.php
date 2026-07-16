<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\DTOs\CatalogDirectoryDefinition;
use App\Models\CatalogCollection;
use App\Models\ContentRequest;
use App\Models\Tag;
use App\Models\UserProfile;
use App\Services\Catalog\CatalogDirectoryRegistry;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Tags\TagQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

final readonly class PortalSearchSuggestionQuery
{
    private const SOURCE_LIMIT = 4;

    public function __construct(
        private CatalogSearchNormalizer $normalizer,
        private CatalogDirectoryRegistry $directories,
        private CatalogTaxonomyRegistry $taxonomies,
        private CatalogTitleQuery $titles,
        private TagQuery $tags,
        private HeaderPortalSectionRegistry $sections,
    ) {}

    /**
     * @return Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>
     */
    public function search(string $query, int $limit = 12): Collection
    {
        $query = mb_substr($this->normalizer->display($query), 0, 80);
        $needle = $this->normalizer->key($query);

        if (mb_strlen($needle) < 2) {
            return collect();
        }

        $limit = max(1, min(30, $limit));
        $results = $this->taxonomySuggestions($query, $needle)
            ->concat($this->tagSuggestions($query, $needle))
            ->concat($this->collectionSuggestions($query, $needle))
            ->concat($this->contentRequestSuggestions($query, $needle))
            ->concat($this->profileSuggestions($query, $needle))
            ->concat($this->yearSuggestions($needle))
            ->concat($this->sections->search($query, self::SOURCE_LIMIT)->map(
                static fn (array $section): array => [
                    'id' => $section['id'],
                    'type' => 'section',
                    'group' => 'sections',
                    'label' => $section['label'],
                    'url' => $section['url'],
                    'meta' => $section['meta'],
                    'rank' => $section['rank'],
                ],
            ));

        return $results
            ->unique(fn (array $item): string => $item['type'].'|'.$item['url'])
            ->sortBy([
                ['rank', 'asc'],
                [fn (array $item): int => $this->groupPriority($item['group']), 'asc'],
                ['label', 'asc'],
            ])
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>
     */
    private function taxonomySuggestions(string $query, string $needle): Collection
    {
        $variants = $this->searchVariants($query);

        return $this->directories->all()
            ->filter(fn (CatalogDirectoryDefinition $directory): bool => $directory->filterType !== null
                && $directory->filterType->value !== 'tag')
            ->flatMap(function (CatalogDirectoryDefinition $directory) use ($needle, $variants): Collection {
                $filterType = $directory->filterType?->value;

                if (! is_string($filterType)) {
                    return collect();
                }

                $modelClass = $this->taxonomies->modelClass($filterType);
                $table = (new $modelClass)->getTable();
                $query = $modelClass::query()
                    ->select([$table.'.id', $table.'.name', $table.'.slug'])
                    ->when(
                        $modelClass === Tag::class,
                        fn (Builder $builder): Builder => $builder->publiclyEligible(),
                    )
                    ->whereHas(
                        'catalogTitles',
                        fn (Builder $builder): Builder => $this->titles->constrainVisible($builder, null),
                    )
                    ->where(fn (Builder $builder): Builder => $this->applyNameSearch($builder, $table.'.name', $variants))
                    ->orderBy($table.'.name')
                    ->orderBy($table.'.id')
                    ->limit(self::SOURCE_LIMIT);

                return $query->get()->map(function (Model $item) use ($filterType, $needle): array {
                    $label = (string) $item->getAttribute('name');
                    $slug = (string) $item->getAttribute('slug');

                    return [
                        'id' => 'portal-'.$filterType.'-'.$item->getKey(),
                        'type' => $filterType,
                        'group' => in_array($filterType, ['actor', 'director'], true) ? 'people' : 'directories',
                        'label' => $label,
                        'url' => route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $slug]),
                        'meta' => (string) __("catalog.taxonomy.{$filterType}"),
                        'rank' => $this->rank($label, $needle),
                    ];
                });
            })
            ->values();
    }

    /**
     * @return Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>
     */
    private function tagSuggestions(string $query, string $needle): Collection
    {
        return $this->tags->searchPublic($query, self::SOURCE_LIMIT)
            ->map(function (Tag $tag) use ($needle): array {
                $count = (int) $tag->public_titles_count;

                return [
                    'id' => 'portal-tag-'.$tag->getKey(),
                    'type' => 'tag',
                    'group' => 'directories',
                    'label' => $tag->name,
                    'url' => route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $tag->slug]),
                    'meta' => trans_choice('catalog.global_search.tag_titles', $count, [
                        'count' => Number::format($count, locale: app()->currentLocale()),
                    ]),
                    'rank' => $this->rank($tag->name, $needle),
                ];
            });
    }

    /**
     * @return Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>
     */
    private function collectionSuggestions(string $query, string $needle): Collection
    {
        $variants = $this->searchVariants($query);

        return CatalogCollection::query()
            ->select(['id', 'public_id', 'name', 'slug'])
            ->publiclyListed()
            ->where(fn (Builder $builder): Builder => $this->applyNameSearch($builder, 'name', $variants))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::SOURCE_LIMIT)
            ->get()
            ->map(fn (CatalogCollection $collection): array => [
                'id' => 'portal-collection-'.$collection->public_id,
                'type' => 'collection',
                'group' => 'community',
                'label' => $collection->name,
                'url' => route('collections.show', ['collectionSlug' => $collection->slug]),
                'meta' => (string) __('catalog.header_search.meta.collection'),
                'rank' => $this->rank($collection->name, $needle),
            ]);
    }

    /**
     * @return Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>
     */
    private function contentRequestSuggestions(string $query, string $needle): Collection
    {
        $variants = $this->searchVariants($query);

        return ContentRequest::query()
            ->select(['id', 'public_id', 'title', 'type', 'status', 'release_year'])
            ->publiclyVisible()
            ->where(fn (Builder $builder): Builder => $this->applyNameSearch($builder, 'title', $variants))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::SOURCE_LIMIT)
            ->get()
            ->map(fn (ContentRequest $request): array => [
                'id' => 'portal-content-request-'.$request->public_id,
                'type' => 'content_request',
                'group' => 'community',
                'label' => $request->title,
                'url' => route('requests.show', $request),
                'meta' => (string) __('catalog.header_search.meta.content_request'),
                'rank' => $this->rank($request->title, $needle),
            ]);
    }

    /**
     * @return Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>
     */
    private function profileSuggestions(string $query, string $needle): Collection
    {
        $variants = $this->searchVariants($query);

        return UserProfile::query()
            ->select(['user_profiles.user_id', 'user_profiles.username', 'users.name as display_name'])
            ->join('users', 'users.id', '=', 'user_profiles.user_id')
            ->publiclyVisible()
            ->where(function (Builder $builder) use ($variants): void {
                $builder->where(fn (Builder $search): Builder => $this->applyNameSearch($search, 'user_profiles.username', $variants))
                    ->orWhere(fn (Builder $search): Builder => $this->applyNameSearch($search, 'users.name', $variants));
            })
            ->orderBy('user_profiles.username')
            ->limit(self::SOURCE_LIMIT)
            ->get()
            ->map(function (UserProfile $profile) use ($needle): array {
                $name = trim((string) $profile->getAttribute('display_name'));
                $label = $name !== '' ? $name : $profile->username;

                return [
                    'id' => 'portal-profile-'.$profile->user_id,
                    'type' => 'profile',
                    'group' => 'community',
                    'label' => $label,
                    'url' => route('users.show', ['username' => $profile->username]),
                    'meta' => (string) __('catalog.header_search.meta.profile'),
                    'rank' => min($this->rank($label, $needle), $this->rank($profile->username, $needle)),
                ];
            });
    }

    /**
     * @return Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>
     */
    private function yearSuggestions(string $needle): Collection
    {
        if (preg_match('/^(?:19|20)\d{2}$/', $needle) !== 1) {
            return collect();
        }

        $year = (int) $needle;
        $exists = $this->titles->visibleTo(null)->where('year', $year)->exists();

        if (! $exists) {
            return collect();
        }

        return collect([[
            'id' => 'portal-year-'.$year,
            'type' => 'year',
            'group' => 'directories',
            'label' => (string) $year,
            'url' => route('years.show', ['value' => $year]),
            'meta' => (string) __('catalog.header_search.meta.year'),
            'rank' => 0,
        ]]);
    }

    /** @return Collection<int, string> */
    private function searchVariants(string $query): Collection
    {
        $safe = str_replace(['%', '_'], '', $query);

        return collect($this->normalizer->legacyVariants($safe))
            ->filter(fn (string $variant): bool => $variant !== '')
            ->take(6)
            ->values();
    }

    /** @param Collection<int, string> $variants */
    private function applyNameSearch(Builder $query, string $column, Collection $variants): Builder
    {
        $variants->each(fn (string $variant): Builder => $query->orWhere($column, 'like', "%{$variant}%"));

        return $query;
    }

    private function rank(string $label, string $needle): int
    {
        $label = $this->normalizer->key($label);

        return match (true) {
            $label === $needle => 0,
            str_starts_with($label, $needle) => 1,
            str_contains(' '.$label, ' '.$needle) => 2,
            str_contains($label, $needle) => 3,
            default => 4,
        };
    }

    private function groupPriority(string $group): int
    {
        return match ($group) {
            'people' => 0,
            'directories' => 1,
            'community' => 2,
            default => 3,
        };
    }
}
