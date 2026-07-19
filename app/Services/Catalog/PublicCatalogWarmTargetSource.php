<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\PublicCacheWarmBatch;
use App\DTOs\PublicCacheWarmTarget;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\LicensedMedia;
use App\Models\Tag;
use App\Services\Collections\CatalogCollectionSchema;
use App\Services\ContentRequests\ContentRequestSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

final class PublicCatalogWarmTargetSource
{
    private const SOURCES = [
        'fixed',
        'catalog_pages',
        'directories',
        'discovery',
        'top_lists',
        'titles',
        'years',
        'taxonomies',
        'collections',
        'requests',
        'documents',
        'public_api',
    ];

    public function __construct(
        private readonly CatalogDirectoryRegistry $directoryRegistry,
        private readonly CatalogDirectoryQuery $directoryQuery,
        private readonly CatalogTaxonomyRegistry $taxonomyRegistry,
        private readonly CatalogCollectionSchema $collectionSchema,
        private readonly ContentRequestSchema $contentRequestSchema,
    ) {}

    /**
     * @param  array{source: string, position: array<string, int|string>}|null  $cursor
     */
    public function batch(?array $cursor, int $limit): PublicCacheWarmBatch
    {
        $limit = max(1, $limit);
        [$sourceIndex, $position] = $this->normalizeCursor($cursor);
        $targets = [];
        $seen = [];

        while (count($targets) < $limit && isset(self::SOURCES[$sourceIndex])) {
            $source = self::SOURCES[$sourceIndex];
            $result = $this->sourceBatch($source, $position, $limit - count($targets));

            foreach ($result['targets'] as $target) {
                if (! $this->validRelativeUrl($target->relativeUrl) || isset($seen[$target->relativeUrl])) {
                    continue;
                }

                $seen[$target->relativeUrl] = true;
                $targets[] = $target;
            }

            if ($result['completed']) {
                $sourceIndex++;
                $position = [];

                continue;
            }

            if ($result['position'] === $position && $result['targets'] === []) {
                throw new InvalidArgumentException("Источник {$source} не продвинул cursor.");
            }

            $position = $result['position'];
        }

        $completed = ! isset(self::SOURCES[$sourceIndex]);

        return new PublicCacheWarmBatch(
            targets: $targets,
            nextCursor: $completed ? null : [
                'source' => self::SOURCES[$sourceIndex],
                'position' => $position,
            ],
            completed: $completed,
        );
    }

    /** @return array{targets: int, by_source: array<string, int>} */
    public function estimate(): array
    {
        $total = 0;
        $bySource = [];

        foreach (self::SOURCES as $source) {
            $count = $this->estimateSource($source);
            $bySource[$source] = $count;
            $total += $count;
        }

        return ['targets' => $total, 'by_source' => $bySource];
    }

    private function estimateSource(string $source): int
    {
        return match ($source) {
            'fixed' => count($this->fixedTargets()),
            'catalog_pages' => $this->pages(CatalogTitle::query()->availableTo(null)->count(), 24),
            'directories' => $this->directoryRegistry->all()->sum(
                fn ($directory): int => $this->pages(
                    $this->directoryQuery->summary($directory)['values'],
                    $directory->perPage,
                ),
            ),
            'discovery' => collect(CatalogRecommendationType::publicCases())
                ->filter(fn (CatalogRecommendationType $type): bool => $type->isIndexable())
                ->count()
                * (1 + count($this->locales('catalog-collections.supported_locales')))
                * $this->pages(
                    max(1, (int) config('recommendations.candidate_limit', 180)),
                    max(1, (int) config('recommendations.page_size', 24)),
                ),
            'top_lists' => count($this->topListTargets()),
            'titles' => CatalogTitle::query()->availableTo(null)->count(),
            'years' => CatalogTitle::query()
                ->availableTo(null)
                ->whereNotNull('year')
                ->selectRaw('year, count(*) as public_titles_count')
                ->groupBy('year')
                ->get()
                ->sum(fn (CatalogTitle $title): int => $this->pages(
                    (int) $title->getAttribute('public_titles_count'),
                    24,
                )),
            'taxonomies' => $this->estimateTaxonomyTargets(),
            'collections' => $this->estimateCollectionTargets(),
            'requests' => $this->estimateRequestTargets(),
            'documents' => count($this->documentTargets()),
            'public_api' => count($this->publicApiTargets()),
            default => throw new InvalidArgumentException("Неизвестный источник полного прогрева: {$source}."),
        };
    }

    private function estimateTaxonomyTargets(): int
    {
        $targets = 0;

        foreach ($this->taxonomyRegistry->relations() as $type => $relation) {
            $modelClass = $relation['model'];
            $model = new $modelClass;
            $modelTable = $model->getTable();
            $pivot = $this->taxonomyRegistry->pivot($type);
            $pivotTable = $pivot['table'];
            $relatedColumn = $pivotTable.'.'.$pivot['related_key'];
            $query = DB::table($pivotTable)
                ->join($modelTable, $relatedColumn, '=', $modelTable.'.'.$model->getKeyName())
                ->whereIn(
                    $pivotTable.'.'.$pivot['title_key'],
                    CatalogTitle::query()->availableTo(null)->select('catalog_titles.id'),
                )
                ->selectRaw($relatedColumn.' as taxonomy_id, count(*) as public_titles_count')
                ->groupBy($relatedColumn);

            if ($modelClass === Tag::class) {
                $query->whereIn(
                    $relatedColumn,
                    Tag::query()->select('tags.id')->publiclyEligible(),
                );
            }

            foreach ($query->cursor() as $row) {
                $targets += $this->pages((int) $row->public_titles_count, 24);
            }
        }

        return $targets;
    }

    private function estimateCollectionTargets(): int
    {
        $count = $this->collectionSchema->available()
            ? CatalogCollection::query()->publiclyListed()->count()
            : 0;
        $variants = 1 + count($this->locales('catalog-collections.supported_locales'));

        return $variants * $this->pages($count, 18);
    }

    private function estimateRequestTargets(): int
    {
        $variants = 1 + count($this->locales('content-requests.supported_locales'));

        if (! $this->contentRequestSchema->ready()) {
            return $variants;
        }

        $count = ContentRequest::query()->publiclyVisible()->count();

        return $variants * (
            $this->pages($count, max(1, (int) config('content-requests.per_page', 20)))
            + $count
        );
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function sourceBatch(string $source, array $position, int $limit): array
    {
        return match ($source) {
            'fixed' => $this->arrayTargets($this->fixedTargets(), $position, $limit),
            'catalog_pages' => $this->catalogPageTargets($position, $limit),
            'directories' => $this->directoryTargets($position, $limit),
            'discovery' => $this->discoveryTargets($position, $limit),
            'top_lists' => $this->arrayTargets($this->topListTargets(), $position, $limit),
            'titles' => $this->titleTargets($position, $limit),
            'years' => $this->yearTargets($position, $limit),
            'taxonomies' => $this->taxonomyTargets($position, $limit),
            'collections' => $this->collectionTargets($position, $limit),
            'requests' => $this->requestTargets($position, $limit),
            'documents' => $this->arrayTargets($this->documentTargets(), $position, $limit),
            'public_api' => $this->arrayTargets($this->publicApiTargets(), $position, $limit),
            default => throw new InvalidArgumentException("Неизвестный источник полного прогрева: {$source}."),
        };
    }

    /** @return list<PublicCacheWarmTarget> */
    private function fixedTargets(): array
    {
        return [
            new PublicCacheWarmTarget(route('home', [], false)),
            ...array_map(
                fn (string $locale): PublicCacheWarmTarget => new PublicCacheWarmTarget(
                    route('localized.home', ['locale' => $locale], false),
                ),
                $this->locales('catalog-collections.supported_locales'),
            ),
            new PublicCacheWarmTarget(route('stats', [], false)),
        ];
    }

    /**
     * @param  list<PublicCacheWarmTarget>  $targets
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function arrayTargets(array $targets, array $position, int $limit): array
    {
        $offset = max(0, (int) ($position['offset'] ?? 0));
        $slice = array_slice($targets, $offset, $limit);
        $nextOffset = $offset + count($slice);

        return [
            'targets' => $slice,
            'position' => ['offset' => $nextOffset],
            'completed' => $nextOffset >= count($targets),
        ];
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function catalogPageTargets(array $position, int $limit): array
    {
        $page = max(1, (int) ($position['page'] ?? 1));
        $pages = $this->pages(CatalogTitle::query()->availableTo(null)->count(), 24);
        $targets = [];

        while ($page <= $pages && count($targets) < $limit) {
            $targets[] = new PublicCacheWarmTarget($this->pageUrl(route('titles.index', [], false), 'page', $page));
            $page++;
        }

        return ['targets' => $targets, 'position' => ['page' => $page], 'completed' => $page > $pages];
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function directoryTargets(array $position, int $limit): array
    {
        $directoryIndex = max(0, (int) ($position['directory_index'] ?? 0));
        $page = max(1, (int) ($position['page'] ?? 1));
        $directories = $this->directoryRegistry->all()->values();
        $targets = [];

        while ($directory = $directories->get($directoryIndex)) {
            $pages = $this->pages($this->directoryQuery->summary($directory)['values'], $directory->perPage);

            while ($page <= $pages && count($targets) < $limit) {
                $targets[] = new PublicCacheWarmTarget($this->pageUrl(
                    route($directory->indexRouteName, [], false),
                    'page',
                    $page,
                ));
                $page++;
            }

            if ($page <= $pages) {
                break;
            }

            $directoryIndex++;
            $page = 1;

            if (count($targets) >= $limit) {
                break;
            }
        }

        return [
            'targets' => $targets,
            'position' => ['directory_index' => $directoryIndex, 'page' => $page],
            'completed' => $directoryIndex >= $directories->count(),
        ];
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function discoveryTargets(array $position, int $limit): array
    {
        $variantIndex = max(0, (int) ($position['variant_index'] ?? 0));
        $page = max(1, (int) ($position['page'] ?? 1));
        $variants = [];

        foreach (CatalogRecommendationType::publicCases() as $type) {
            if (! $type->isIndexable()) {
                continue;
            }

            $variants[] = ['route' => 'discover.index', 'parameters' => ['type' => $type->value]];

            foreach ($this->locales('catalog-collections.supported_locales') as $locale) {
                $variants[] = [
                    'route' => 'localized.discover.index',
                    'parameters' => ['locale' => $locale, 'type' => $type->value],
                ];
            }
        }

        $pages = $this->pages(
            max(1, (int) config('recommendations.candidate_limit', 180)),
            max(1, (int) config('recommendations.page_size', 24)),
        );
        $targets = [];

        while (isset($variants[$variantIndex])) {
            $variant = $variants[$variantIndex];

            while ($page <= $pages && count($targets) < $limit) {
                $targets[] = new PublicCacheWarmTarget($this->pageUrl(
                    route($variant['route'], $variant['parameters'], false),
                    'page',
                    $page,
                ));
                $page++;
            }

            if ($page <= $pages) {
                break;
            }

            $variantIndex++;
            $page = 1;

            if (count($targets) >= $limit) {
                break;
            }
        }

        return [
            'targets' => $targets,
            'position' => ['variant_index' => $variantIndex, 'page' => $page],
            'completed' => ! isset($variants[$variantIndex]),
        ];
    }

    /** @return list<PublicCacheWarmTarget> */
    private function topListTargets(): array
    {
        $route = Route::getRoutes()->getByName('top.show');

        if (! $route instanceof LaravelRoute) {
            return [];
        }

        $categoryPattern = $route->wheres['category'] ?? null;

        if (! is_string($categoryPattern) || $categoryPattern === '') {
            return [];
        }

        $categories = array_values(array_filter(
            explode('|', $categoryPattern),
            static fn (string $category): bool => preg_match('/^[a-z0-9_-]+$/D', $category) === 1,
        ));
        $targets = [];
        $hasLocalizedRoute = Route::has('localized.top.show');

        foreach ($categories as $category) {
            $targets[] = new PublicCacheWarmTarget(route('top.show', [
                'category' => $category,
            ], false));

            foreach ($hasLocalizedRoute ? $this->locales('catalog-collections.supported_locales') : [] as $locale) {
                $targets[] = new PublicCacheWarmTarget(route('localized.top.show', [
                    'locale' => $locale,
                    'category' => $category,
                ], false));
            }
        }

        return $targets;
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function titleTargets(array $position, int $limit): array
    {
        $lastId = max(0, (int) ($position['last_id'] ?? 0));
        $titles = CatalogTitle::query()
            ->availableTo(null)
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'slug']);
        $targets = $titles
            ->map(fn (CatalogTitle $title): PublicCacheWarmTarget => new PublicCacheWarmTarget(
                route('titles.show', ['catalogTitle' => $title->slug], false),
            ))
            ->all();
        $lastTitle = $titles->last();
        $nextLastId = $lastTitle instanceof CatalogTitle ? (int) $lastTitle->id : $lastId;

        return [
            'targets' => $targets,
            'position' => ['last_id' => $nextLastId],
            'completed' => $titles->count() < $limit,
        ];
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function yearTargets(array $position, int $limit): array
    {
        $lastYear = max(0, (int) ($position['last_year'] ?? 0));
        $currentYear = max(0, (int) ($position['current_year'] ?? 0));
        $page = max(1, (int) ($position['page'] ?? 1));
        $targets = [];

        while (count($targets) < $limit) {
            $row = CatalogTitle::query()
                ->availableTo(null)
                ->whereNotNull('year')
                ->when($currentYear > 0, fn (Builder $query): Builder => $query->where('year', $currentYear))
                ->when($currentYear === 0, fn (Builder $query): Builder => $query->where('year', '>', $lastYear))
                ->selectRaw('year, count(*) as public_titles_count')
                ->groupBy('year')
                ->orderBy('year')
                ->first();

            if (! $row instanceof CatalogTitle) {
                return [
                    'targets' => $targets,
                    'position' => ['last_year' => $lastYear, 'current_year' => 0, 'page' => 1],
                    'completed' => true,
                ];
            }

            $year = (int) $row->year;
            $pages = $this->pages((int) $row->getAttribute('public_titles_count'), 24);

            while ($page <= $pages && count($targets) < $limit) {
                $targets[] = new PublicCacheWarmTarget($this->pageUrl(
                    route('titles.year', ['year' => $year], false),
                    'page',
                    $page,
                ));
                $page++;
            }

            if ($page <= $pages) {
                return [
                    'targets' => $targets,
                    'position' => ['last_year' => $lastYear, 'current_year' => $year, 'page' => $page],
                    'completed' => false,
                ];
            }

            $lastYear = $year;
            $currentYear = 0;
            $page = 1;
        }

        return [
            'targets' => $targets,
            'position' => ['last_year' => $lastYear, 'current_year' => $currentYear, 'page' => $page],
            'completed' => false,
        ];
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function taxonomyTargets(array $position, int $limit): array
    {
        $relations = array_map(
            fn (string $type, array $config): array => ['type' => $type, ...$config],
            array_keys($this->taxonomyRegistry->relations()),
            $this->taxonomyRegistry->relations(),
        );
        $typeIndex = max(0, (int) ($position['type_index'] ?? 0));
        $lastId = max(0, (int) ($position['last_id'] ?? 0));
        $currentId = max(0, (int) ($position['current_id'] ?? 0));
        $page = max(1, (int) ($position['page'] ?? 1));
        $targets = [];

        while (isset($relations[$typeIndex]) && count($targets) < $limit) {
            $relation = $relations[$typeIndex];
            $modelClass = $relation['model'];

            if ($modelClass === Tag::class) {
                $query = Tag::query()
                    ->select(['id', 'slug'])
                    ->publiclyEligible();
            } else {
                $query = $modelClass::query()->select(['id', 'slug']);
            }

            $query
                ->withCount(['catalogTitles as public_titles_count' => fn (Builder $query): Builder => $this->publicTitleConstraint($query)])
                ->whereHas('catalogTitles', fn (Builder $query): Builder => $this->publicTitleConstraint($query));

            $remaining = $limit - count($targets);
            $taxonomies = $currentId > 0
                ? $query->whereKey($currentId)->limit(1)->get()
                : $query->where('id', '>', $lastId)->orderBy('id')->limit($remaining)->get();

            if ($taxonomies->isEmpty()) {
                $typeIndex++;
                $lastId = 0;
                $currentId = 0;
                $page = 1;

                continue;
            }

            foreach ($taxonomies as $taxonomy) {
                $taxonomyId = (int) $taxonomy->getKey();
                $pages = $this->pages((int) $taxonomy->getAttribute('public_titles_count'), 24);
                $baseUrl = route('titles.taxonomy', [
                    'type' => $relation['type'],
                    'taxonomy' => (string) $taxonomy->getAttribute('slug'),
                ], false);

                while ($page <= $pages && count($targets) < $limit) {
                    $targets[] = new PublicCacheWarmTarget($this->pageUrl($baseUrl, 'page', $page));
                    $page++;
                }

                if ($page <= $pages) {
                    return [
                        'targets' => $targets,
                        'position' => [
                            'type_index' => $typeIndex,
                            'last_id' => $lastId,
                            'current_id' => $taxonomyId,
                            'page' => $page,
                        ],
                        'completed' => false,
                    ];
                }

                $lastId = $taxonomyId;
                $currentId = 0;
                $page = 1;
            }
        }

        return [
            'targets' => $targets,
            'position' => [
                'type_index' => $typeIndex,
                'last_id' => $lastId,
                'current_id' => $currentId,
                'page' => $page,
            ],
            'completed' => ! isset($relations[$typeIndex]),
        ];
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function collectionTargets(array $position, int $limit): array
    {
        $variants = [
            ['route' => 'discover.index', 'parameters' => ['type' => 'popular']],
            ...array_map(fn (string $locale): array => [
                'route' => 'localized.discover.index',
                'parameters' => ['locale' => $locale, 'type' => 'popular'],
            ], $this->locales('catalog-collections.supported_locales')),
        ];
        $variantIndex = max(0, (int) ($position['variant_index'] ?? 0));
        $page = max(1, (int) ($position['page'] ?? 1));
        $count = $this->collectionSchema->available()
            ? CatalogCollection::query()->publiclyListed()->count()
            : 0;
        $pages = $this->pages($count, 12);
        $targets = [];

        while (isset($variants[$variantIndex])) {
            $variant = $variants[$variantIndex];

            while ($page <= $pages && count($targets) < $limit) {
                $targets[] = new PublicCacheWarmTarget($this->pageUrl(
                    route($variant['route'], $variant['parameters'], false),
                    'collectionsPage',
                    $page,
                ));
                $page++;
            }

            if ($page <= $pages) {
                break;
            }

            $variantIndex++;
            $page = 1;

            if (count($targets) >= $limit) {
                break;
            }
        }

        return [
            'targets' => $targets,
            'position' => ['variant_index' => $variantIndex, 'page' => $page],
            'completed' => ! isset($variants[$variantIndex]),
        ];
    }

    /**
     * @param  array<string, int|string>  $position
     * @return array{targets: list<PublicCacheWarmTarget>, position: array<string, int|string>, completed: bool}
     */
    private function requestTargets(array $position, int $limit): array
    {
        $stage = (string) ($position['stage'] ?? 'directories');
        $variantIndex = max(0, (int) ($position['variant_index'] ?? 0));
        $page = max(1, (int) ($position['page'] ?? 1));
        $lastId = max(0, (int) ($position['last_id'] ?? 0));
        $currentId = max(0, (int) ($position['current_id'] ?? 0));
        $variants = [null, ...$this->locales('content-requests.supported_locales')];
        $targets = [];

        if ($stage === 'directories') {
            $count = $this->contentRequestSchema->ready()
                ? ContentRequest::query()->publiclyVisible()->count()
                : 0;
            $pages = $this->pages($count, max(1, (int) config('content-requests.per_page', 20)));

            while (array_key_exists($variantIndex, $variants)) {
                $locale = $variants[$variantIndex];
                $routeName = $locale === null ? 'requests.index' : 'localized.requests.index';
                $parameters = $locale === null ? [] : ['locale' => $locale];

                while ($page <= $pages && count($targets) < $limit) {
                    $targets[] = new PublicCacheWarmTarget($this->pageUrl(
                        route($routeName, $parameters, false),
                        'requestsPage',
                        $page,
                    ));
                    $page++;
                }

                if ($page <= $pages) {
                    return [
                        'targets' => $targets,
                        'position' => [
                            'stage' => 'directories',
                            'variant_index' => $variantIndex,
                            'page' => $page,
                            'last_id' => 0,
                            'current_id' => 0,
                        ],
                        'completed' => false,
                    ];
                }

                $variantIndex++;
                $page = 1;

                if (count($targets) >= $limit) {
                    return [
                        'targets' => $targets,
                        'position' => [
                            'stage' => 'directories',
                            'variant_index' => $variantIndex,
                            'page' => 1,
                            'last_id' => 0,
                            'current_id' => 0,
                        ],
                        'completed' => false,
                    ];
                }
            }

            $stage = 'details';
            $variantIndex = 0;
        }

        while (count($targets) < $limit && $this->contentRequestSchema->ready()) {
            $request = ContentRequest::query()
                ->publiclyVisible()
                ->when($currentId > 0, fn (Builder $query): Builder => $query->whereKey($currentId))
                ->when($currentId === 0, fn (Builder $query): Builder => $query->where('id', '>', $lastId)->orderBy('id'))
                ->first(['id', 'public_id']);

            if (! $request instanceof ContentRequest) {
                break;
            }

            $currentId = (int) $request->id;

            while (array_key_exists($variantIndex, $variants) && count($targets) < $limit) {
                $locale = $variants[$variantIndex];
                $routeName = $locale === null ? 'requests.show' : 'localized.requests.show';
                $parameters = ['contentRequest' => $request->public_id];

                if ($locale !== null) {
                    $parameters['locale'] = $locale;
                }

                $targets[] = new PublicCacheWarmTarget(route($routeName, $parameters, false));
                $variantIndex++;
            }

            if (array_key_exists($variantIndex, $variants)) {
                return [
                    'targets' => $targets,
                    'position' => [
                        'stage' => 'details',
                        'variant_index' => $variantIndex,
                        'page' => 1,
                        'last_id' => $lastId,
                        'current_id' => $currentId,
                    ],
                    'completed' => false,
                ];
            }

            $lastId = $currentId;
            $currentId = 0;
            $variantIndex = 0;
        }

        $completed = ! $this->contentRequestSchema->ready()
            || ! ContentRequest::query()->publiclyVisible()->where('id', '>', $lastId)->exists();

        return [
            'targets' => $targets,
            'position' => [
                'stage' => 'details',
                'variant_index' => $variantIndex,
                'page' => 1,
                'last_id' => $lastId,
                'current_id' => $currentId,
            ],
            'completed' => $completed,
        ];
    }

    /** @return list<PublicCacheWarmTarget> */
    private function documentTargets(): array
    {
        $targets = collect([
            'sitemap',
            'sitemap.index',
            'sitemap.static',
            'sitemap.taxonomies',
            'sitemap.landings',
            'sitemap.collections',
            'feed',
            'opensearch',
            'llms',
        ])->map(fn (string $routeName): PublicCacheWarmTarget => new PublicCacheWarmTarget(
            route($routeName, [], false),
            str_starts_with($routeName, 'sitemap') ? 'application/xml' : 'text/plain',
            'document',
        ));
        $titlePages = $this->pages(CatalogTitle::query()->availableTo(null)->count(), 10_000);

        for ($page = 1; $page <= $titlePages; $page++) {
            $targets->push(new PublicCacheWarmTarget(
                route('sitemap.titles', ['page' => $page], false),
                'application/xml',
                'document',
            ));
        }

        $videoCount = LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->withPlaybackLocation()
            ->withoutKnownFailures()
            ->whereIn('catalog_title_id', CatalogTitle::query()->availableTo(null)->select('id'))
            ->count();

        for ($page = 1; $page <= $this->pages($videoCount, 5_000); $page++) {
            $targets->push(new PublicCacheWarmTarget(
                route('sitemap.videos', ['page' => $page], false),
                'application/xml',
                'document',
            ));
        }

        if ($this->contentRequestSchema->ready()) {
            $requestCount = ContentRequest::query()
                ->publiclyVisible()
                ->whereIn('status', ['approved', 'planned', 'in_progress', 'partially_completed', 'completed'])
                ->count();

            for ($page = 1; $page <= (int) ceil($requestCount / 10_000); $page++) {
                $targets->push(new PublicCacheWarmTarget(
                    route('sitemap.requests', ['page' => $page], false),
                    'application/xml',
                    'document',
                ));
            }
        }

        return $targets->all();
    }

    /** @return list<PublicCacheWarmTarget> */
    private function publicApiTargets(): array
    {
        return collect([
            'api.discovery',
            'api.openapi',
            'api.titles.index',
            'api.v1.catalog.directories.index',
            'api.v1.catalog.filters',
            'api.v1.config',
            'api.v1.home',
            'api.v1.tags.index',
            'api.v1.titles.index',
        ])->map(fn (string $routeName): PublicCacheWarmTarget => new PublicCacheWarmTarget(
            route($routeName, [], false),
            'application/json',
            'api',
        ))->all();
    }

    /**
     * @param  array<string, mixed>|null  $cursor
     * @return array{int, array<string, int|string>}
     */
    private function normalizeCursor(?array $cursor): array
    {
        if ($cursor === null) {
            return [0, []];
        }

        $source = $cursor['source'] ?? null;
        $position = $cursor['position'] ?? null;
        $sourceIndex = is_string($source) ? array_search($source, self::SOURCES, true) : false;

        if ($sourceIndex === false || ! is_array($position)) {
            throw new InvalidArgumentException('Cursor полного прогрева имеет недопустимый формат.');
        }

        $normalizedPosition = [];

        foreach ($position as $key => $value) {
            if (! is_string($key) || (! is_int($value) && ! is_string($value))) {
                throw new InvalidArgumentException('Cursor полного прогрева содержит недопустимое значение.');
            }

            $normalizedPosition[$key] = $value;
        }

        return [$sourceIndex, $normalizedPosition];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function publicTitleConstraint(Builder $query): Builder
    {
        return $query->whereIn(
            'catalog_titles.id',
            CatalogTitle::query()->availableTo(null)->select('catalog_titles.id'),
        );
    }

    private function pageUrl(string $baseUrl, string $pageName, int $page): string
    {
        return $page > 1 ? $baseUrl.'?'.http_build_query([$pageName => $page]) : $baseUrl;
    }

    private function pages(int $items, int $perPage): int
    {
        return max(1, (int) ceil(max(0, $items) / max(1, $perPage)));
    }

    /** @return list<string> */
    private function locales(string $configKey): array
    {
        return collect((array) config($configKey, []))
            ->filter(fn (mixed $locale): bool => is_string($locale) && $locale !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function validRelativeUrl(string $url): bool
    {
        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_contains($url, "\n")
            && ! str_contains($url, "\r");
    }
}
