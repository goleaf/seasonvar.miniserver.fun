<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogSeoBuilder
{
    /**
     * @param  array{titles: int, episodes: int, genres: int, countries: int}  $stats
     * @return array<string, mixed>
     */
    public function home(array $stats, Collection $latestTitles): array
    {
        $title = 'Сериалы онлайн - '.$this->siteName();
        $description = 'Каталог сериалов онлайн: '.$stats['titles'].' сериалов, '.$stats['episodes'].' серий, фильтры по жанрам, странам, актерам, годам и переводам.';

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => 'сериалы онлайн, смотреть сериалы, каталог сериалов, новые серии, сезоны сериалов',
            'news_keywords' => 'сериалы онлайн, смотреть сериалы онлайн, новые серии сериалов, каталог сериалов',
            'canonical' => route('home'),
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'section' => 'Каталог сериалов',
            'tags' => ['сериалы онлайн', 'каталог сериалов', 'новые серии', 'сезоны сериалов'],
            'seo_text' => [
                'Каталог автоматически собирает сериалы, сезоны, серии, постеры, жанры, страны, актеров, режиссеров и доступные видео для просмотра во встроенном плеере.',
                'На главной странице появляются последние обновления, поэтому поисковые страницы получают актуальную информацию после каждого обновления каталога.',
            ],
            'search_phrases' => [
                'смотреть сериалы онлайн',
                'новые серии сериалов',
                'каталог сериалов по жанрам',
                'сериалы по странам',
                'сериалы по актерам',
                'сериалы с сезонами и сериями',
                'сериалы во встроенном плеере',
                'обновления сериалов онлайн',
            ],
            'keyword_clusters' => [
                [
                    'title' => 'Смотреть онлайн',
                    'items' => ['смотреть сериалы онлайн', 'сериалы онлайн бесплатно', 'сериалы в плеере', 'новые серии онлайн'],
                ],
                [
                    'title' => 'Каталог и фильтры',
                    'items' => ['сериалы по жанрам', 'сериалы по странам', 'сериалы по актерам', 'сериалы по годам'],
                ],
                [
                    'title' => 'Сезоны и серии',
                    'items' => ['все сезоны сериалов', 'все серии сериалов', 'обновления сезонов', 'новые эпизоды'],
                ],
            ],
            'related_links' => [
                ['name' => 'Все сериалы онлайн', 'url' => route('titles.index')],
                ['name' => 'Сериалы '.now()->year.' года', 'url' => route('titles.year', ['year' => now()->year])],
                ['name' => 'RSS обновлений', 'url' => route('feed')],
            ],
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => route('home')],
            ],
            'jsonLd' => [
                $this->organizationJsonLd(),
                $this->websiteJsonLd(),
                $this->siteNavigationJsonLd(),
                $this->webPageJsonLd($title, $description, route('home'), 'WebPage', ['сериалы онлайн', 'каталог сериалов']),
                $this->collectionPageJsonLd($title, $description, route('home')),
                $this->itemListJsonLd($latestTitles, 1, 'Новые сериалы каталога'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function titles(
        Request $request,
        int $total,
        string $search,
        bool $searchFallback,
        ?int $year,
        Collection $activeTaxonomies,
        array $invalidFilterSlugs,
        bool $invalidYear,
        string $requestedYear,
        int $currentPage,
        ?string $previousPageUrl,
        ?string $nextPageUrl,
        Collection $pageTitles,
        int $firstItemPosition,
        ?CatalogTitle $titleContext,
    ): array {
        $activeLabels = $activeTaxonomies
            ->map(fn (Model $record, string $filterType): string => $this->filterTypeLabel($filterType).': '.$record->name)
            ->values();
        $filterText = $activeLabels->implode(', ');
        $title = match (true) {
            $search !== '' && $titleContext !== null => 'Поиск "'.$search.'" по сериалу '.$titleContext->title,
            $search !== '' => 'Поиск "'.$search.'" - сериалы онлайн',
            $filterText !== '' && $year !== null => 'Сериалы '.$year.' - '.$filterText,
            $filterText !== '' => 'Сериалы - '.$filterText,
            $year !== null => 'Сериалы '.$year.' года онлайн',
            $titleContext !== null => 'Подборка по сериалу '.$titleContext->title,
            default => 'Все сериалы онлайн',
        };

        if ($currentPage > 1) {
            $title .= ' - страница '.$currentPage;
        }

        $descriptionParts = collect([
            $total.' сериалов найдено в каталоге.',
            $titleContext !== null ? 'Показаны результаты по сериалу '.$titleContext->title.'.' : null,
            $filterText !== '' ? 'Фильтры: '.$filterText.'.' : null,
            $year !== null ? 'Год выхода: '.$year.'.' : null,
            $search !== '' ? 'Поисковый запрос: '.$search.'.' : null,
            $searchFallback ? 'Точных совпадений не найдено, поэтому показаны ближайшие страницы каталога.' : null,
            $invalidYear ? 'Год '.$requestedYear.' не найден.' : null,
            $invalidFilterSlugs !== [] ? 'Часть фильтров не найдена.' : null,
        ])->filter()->implode(' ');
        $canonical = $this->catalogCanonicalUrl($request, $activeTaxonomies, $year, $currentPage, $titleContext);
        $robots = $search === '' && $invalidFilterSlugs === [] && ! $invalidYear && $activeTaxonomies->count() <= 1
            ? $this->indexRobots()
            : 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        $keywords = collect(['сериалы онлайн', 'каталог сериалов', 'смотреть сериалы'])
            ->when($titleContext !== null, fn (Collection $items): Collection => $items->push($titleContext->title))
            ->merge($activeTaxonomies->pluck('name'))
            ->when($year !== null, fn (Collection $items): Collection => $items->push('сериалы '.$year))
            ->filter()
            ->unique()
            ->values();
        $tags = $keywords->take(20)->all();

        return [
            'title' => $title,
            'h1' => $searchFallback ? 'Поиск "'.$search.'" - ближайшие результаты каталога' : $this->catalogSeoHeading($search, $year, $activeTaxonomies),
            'lead' => $searchFallback
                ? 'По точному запросу совпадений не найдено, поэтому ниже показаны ближайшие страницы каталога, популярные сериалы и связанные подборки.'
                : $this->catalogSeoLead($total, $search, $year, $activeTaxonomies),
            'description' => $this->seoDescription($descriptionParts),
            'keywords' => $keywords->implode(', '),
            'news_keywords' => $keywords->take(10)->implode(', '),
            'canonical' => $canonical,
            'robots' => $robots,
            'prev' => $previousPageUrl,
            'next' => $nextPageUrl,
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'section' => 'Сериалы',
            'tags' => $tags,
            'seo_text' => collect($searchFallback ? [
                'По точному запросу «'.$search.'» совпадений пока нет, поэтому страница автоматически показывает ближайшие результаты каталога и связанные SEO-направления.',
            ] : [])->merge($this->catalogSeoText($total, $search, $year, $activeTaxonomies))->values()->all(),
            'search_phrases' => $this->catalogSearchPhrases($search, $year, $activeTaxonomies),
            'keyword_clusters' => $this->catalogKeywordClusters($search, $year, $activeTaxonomies),
            'related_links' => $this->catalogRelatedLinks($search, $year, $activeTaxonomies),
            'search_context' => $titleContext === null ? null : [
                'type' => 'title',
                'title' => $titleContext->title,
                'slug' => $titleContext->slug,
            ],
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => route('home')],
                ['name' => 'Сериалы', 'url' => route('titles.index')],
            ],
            'jsonLd' => [
                $this->organizationJsonLd(),
                $this->websiteJsonLd(),
                $this->siteNavigationJsonLd(),
                $this->webPageJsonLd($title, $descriptionParts, $canonical, 'CollectionPage', $tags),
                $this->collectionPageJsonLd($title, $descriptionParts, $canonical),
                $this->itemListJsonLd($pageTitles, $firstItemPosition, $title),
                $this->breadcrumbJsonLd([
                    ['name' => 'Главная', 'url' => route('home')],
                    ['name' => 'Сериалы', 'url' => route('titles.index')],
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function title(
        CatalogTitle $catalogTitle,
        Collection $taxonomiesByType,
        Collection $seasons,
        int $episodeCount,
        int $mediaCount,
        ?LicensedMedia $selectedMedia,
        ?string $selectedMediaUrl,
    ): array {
        $genres = $this->taxonomyNames($taxonomiesByType, 'genre');
        $countries = $this->taxonomyNames($taxonomiesByType, 'country');
        $actors = $this->taxonomyNames($taxonomiesByType, 'actor')->take(10);
        $directors = $this->taxonomyNames($taxonomiesByType, 'director')->take(10);
        $ageRatings = $this->taxonomyNames($taxonomiesByType, 'age_rating');
        $fallbackDescription = collect([
            'Сериал '.$catalogTitle->title.' смотреть онлайн.',
            $catalogTitle->year ? 'Год выхода: '.$catalogTitle->year.'.' : null,
            $genres->isNotEmpty() ? 'Жанры: '.$genres->take(5)->implode(', ').'.' : null,
            $countries->isNotEmpty() ? 'Страна: '.$countries->take(3)->implode(', ').'.' : null,
            $seasons->count() > 0 ? 'Сезонов: '.$seasons->count().'.' : null,
            $episodeCount > 0 ? 'Серий: '.$episodeCount.'.' : null,
            $mediaCount > 0 ? 'Видео доступно во встроенном плеере.' : null,
        ])->filter()->implode(' ');
        $description = $this->seoDescription($catalogTitle->description ?: $fallbackDescription, 190);
        $pageTitle = 'Сериал '.$catalogTitle->title.' смотреть онлайн';

        if ($catalogTitle->year) {
            $pageTitle .= ' - '.$catalogTitle->year;
        }

        $alternateNames = collect([$catalogTitle->original_title])
            ->merge($catalogTitle->relationLoaded('aliases') ? $catalogTitle->aliases->pluck('name') : [])
            ->filter()
            ->unique()
            ->values();
        $rating = $catalogTitle->relationLoaded('ratings')
            ? $catalogTitle->ratings->first(fn ($rating): bool => $rating->rating !== null)
            : null;
        $seriesSchema = $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'TVSeries',
            'name' => $catalogTitle->title,
            'alternateName' => $alternateNames->isNotEmpty() ? $alternateNames->all() : null,
            'description' => $description,
            'url' => route('titles.show', $catalogTitle),
            'mainEntityOfPage' => route('titles.show', $catalogTitle),
            'image' => $catalogTitle->poster_url,
            'inLanguage' => 'ru',
            'isAccessibleForFree' => true,
            'publisher' => $this->organizationJsonLd(false),
            'datePublished' => $catalogTitle->year ? (string) $catalogTitle->year : null,
            'genre' => $genres->isNotEmpty() ? $genres->all() : null,
            'countryOfOrigin' => $countries->map(fn (string $name): array => ['@type' => 'Country', 'name' => $name])->values()->all(),
            'actor' => $actors->map(fn (string $name): array => ['@type' => 'Person', 'name' => $name])->values()->all(),
            'director' => $directors->map(fn (string $name): array => ['@type' => 'Person', 'name' => $name])->values()->all(),
            'contentRating' => $ageRatings->first(),
            'numberOfSeasons' => $seasons->count() > 0 ? $seasons->count() : null,
            'numberOfEpisodes' => $episodeCount > 0 ? $episodeCount : null,
            'containsSeason' => $this->seasonJsonLd($catalogTitle, $seasons),
            'aggregateRating' => $rating ? $this->withoutEmpty([
                '@type' => 'AggregateRating',
                'ratingValue' => (float) $rating->rating,
                'bestRating' => 10,
                'worstRating' => 0,
                'ratingCount' => $rating->votes,
            ]) : null,
        ]);
        $faqItems = $this->titleFaqItems($catalogTitle, $genres, $countries, $seasons->count(), $episodeCount, $mediaCount);
        $keywordCollection = $this->titleKeywordCollection($catalogTitle, $alternateNames, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount);
        $searchPhrases = $this->titleSearchPhrases($catalogTitle, $keywordCollection, $genres, $countries, $seasons->count(), $episodeCount, $mediaCount);
        $jsonLd = [
            $this->organizationJsonLd(),
            $this->websiteJsonLd(),
            $this->siteNavigationJsonLd(),
            $this->webPageJsonLd($pageTitle, $description, route('titles.show', $catalogTitle), 'VideoObject', $keywordCollection->take(25)->all()),
            $seriesSchema,
            $this->faqJsonLd($faqItems),
            $this->episodeItemListJsonLd($catalogTitle, $seasons),
            $this->breadcrumbJsonLd([
                ['name' => 'Главная', 'url' => route('home')],
                ['name' => 'Сериалы', 'url' => route('titles.index')],
                ['name' => $catalogTitle->title, 'url' => route('titles.show', $catalogTitle)],
            ]),
        ];

        if ($selectedMediaUrl !== null) {
            $jsonLd[] = $this->withoutEmpty([
                '@context' => 'https://schema.org',
                '@type' => 'VideoObject',
                'name' => $selectedMedia?->title ?: $catalogTitle->title,
                'description' => $description,
                'thumbnailUrl' => $catalogTitle->poster_url,
                'uploadDate' => $this->sitemapDate($catalogTitle->indexed_at ?: $catalogTitle->updated_at),
                'contentUrl' => $selectedMediaUrl,
                'url' => route('titles.show', $catalogTitle).'#player',
            ]);
        }

        return [
            'title' => $pageTitle,
            'description' => $description,
            'keywords' => $keywordCollection->take(45)->implode(', '),
            'news_keywords' => $keywordCollection->take(15)->implode(', '),
            'canonical' => route('titles.show', $catalogTitle),
            'image' => $catalogTitle->poster_url,
            'image_alt' => 'Постер '.$catalogTitle->title,
            'video' => $selectedMediaUrl,
            'published_time' => $this->sitemapDate($catalogTitle->created_at),
            'updated_time' => $this->sitemapDate($catalogTitle->indexed_at ?: $catalogTitle->updated_at),
            'section' => 'Сериал онлайн',
            'tags' => $keywordCollection->take(25)->values()->all(),
            'seo_text' => $this->titleSeoText($catalogTitle, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount),
            'search_phrases' => $searchPhrases,
            'keyword_clusters' => $this->titleKeywordClusters($catalogTitle, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount),
            'related_links' => $this->titleRelatedLinks($catalogTitle, $taxonomiesByType),
            'search_context' => [
                'type' => 'title',
                'title' => $catalogTitle->title,
                'slug' => $catalogTitle->slug,
            ],
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => route('home')],
                ['name' => 'Сериалы', 'url' => route('titles.index')],
                ['name' => $catalogTitle->title, 'url' => route('titles.show', $catalogTitle)],
            ],
            'faq' => $faqItems,
            'type' => 'video.tv_show',
            'jsonLd' => $jsonLd,
        ];
    }

    private function siteName(): string
    {
        return (string) config('app.name', 'Каталог сериалов');
    }

    private function indexRobots(): string
    {
        return 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
    }

    private function seoDescription(?string $value, int $limit = 180): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?: '';

        return $text;
    }

    private function canonicalFromRequest(Request $request): string
    {
        $query = collect($request->query())
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->sortKeys()
            ->all();

        return $query === []
            ? $request->url()
            : $request->url().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function catalogCanonicalUrl(Request $request, Collection $activeTaxonomies, ?int $year, int $currentPage, ?CatalogTitle $titleContext = null): string
    {
        $query = [];

        if ($titleContext !== null) {
            $query['title'] = $titleContext->slug;
        }

        if ($year !== null) {
            $query['year'] = $year;
        }

        if ($currentPage > 1) {
            $query['page'] = $currentPage;
        }

        if ($activeTaxonomies->count() === 1) {
            $filterType = (string) $activeTaxonomies->keys()->first();
            $taxonomy = $activeTaxonomies->first();
            $url = route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $taxonomy->slug]);

            return $query === []
                ? $url
                : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ($activeTaxonomies->isEmpty()) {
            $search = $request->query('q');

            if (is_scalar($search) && trim((string) $search) !== '') {
                $query['q'] = trim((string) $search);
            }

            if ($titleContext === null && $year !== null && ! isset($query['q']) && $currentPage === 1) {
                return route('titles.year', ['year' => $year]);
            }

            return $query === []
                ? route('titles.index')
                : route('titles.index').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->canonicalFromRequest($request);
    }

    /**
     * @return Collection<int, string>
     */
    private function taxonomyNames(Collection $taxonomiesByType, string $type): Collection
    {
        return $taxonomiesByType
            ->get($type, collect())
            ->pluck('name')
            ->filter()
            ->unique()
            ->values();
    }

    private function filterTypeLabel(string $filterType): string
    {
        return [
            'genre' => 'Жанр',
            'country' => 'Страна',
            'actor' => 'Актер',
            'director' => 'Режиссер',
            'age_rating' => 'Возраст',
            'translation' => 'Перевод',
            'status' => 'Статус',
            'network' => 'Канал',
            'studio' => 'Студия',
            'tag' => 'Тег',
        ][$filterType] ?? 'Фильтр';
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->siteName(),
            'url' => route('home'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => route('titles.index').'?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
            'sameAs' => [
                route('sitemap.index'),
                route('feed'),
            ],
            'keywords' => 'сериалы онлайн, каталог сериалов, смотреть сериалы онлайн',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteNavigationJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Навигация сайта',
            'itemListElement' => [
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 1,
                    'name' => 'Главная',
                    'url' => route('home'),
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 2,
                    'name' => 'Все сериалы',
                    'url' => route('titles.index'),
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 3,
                    'name' => 'Поиск',
                    'url' => route('titles.index').'?q={search_term_string}',
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 4,
                    'name' => 'RSS',
                    'url' => route('feed'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationJsonLd(bool $withContext = true): array
    {
        return $this->withoutEmpty([
            '@context' => $withContext ? 'https://schema.org' : null,
            '@type' => 'Organization',
            'name' => $this->siteName(),
            'url' => route('home'),
            'logo' => null,
        ]);
    }

    /**
     * @param  list<string>  $about
     * @return array<string, mixed>
     */
    private function webPageJsonLd(string $name, string $description, string $url, string $type = 'WebPage', array $about = []): array
    {
        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => $type === 'VideoObject' ? 'WebPage' : $type,
            'name' => $name,
            'description' => $this->seoDescription($description),
            'url' => $url,
            'inLanguage' => 'ru',
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $this->siteName(),
                'url' => route('home'),
            ],
            'publisher' => $this->organizationJsonLd(false),
            'mainEntity' => $url,
            'speakable' => [
                '@type' => 'SpeakableSpecification',
                'cssSelector' => ['[data-seo-summary]', 'h1'],
            ],
            'about' => collect($about)
                ->filter()
                ->unique()
                ->take(20)
                ->map(fn (string $name): array => ['@type' => 'Thing', 'name' => $name])
                ->values()
                ->all(),
            'keywords' => collect($about)->filter()->unique()->take(30)->implode(', '),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionPageJsonLd(string $name, string $description, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'description' => $this->seoDescription($description),
            'url' => $url,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $this->siteName(),
                'url' => route('home'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemListJsonLd(Collection $titles, int $startPosition, string $name): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'numberOfItems' => $titles->count(),
            'itemListElement' => $titles
                ->values()
                ->map(fn (CatalogTitle $title, int $index): array => $this->withoutEmpty([
                    '@type' => 'ListItem',
                    'position' => $startPosition + $index,
                    'url' => route('titles.show', $title),
                    'name' => $title->title,
                    'image' => $title->poster_url,
                ]))
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function catalogSeoText(int $total, string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $parts = collect([
            $total.' сериалов найдено в текущей подборке каталога.',
            $year !== null ? 'Подборка сфокусирована на сериалах '.$year.' года.' : null,
            $search !== '' ? 'Поиск учитывает название, оригинальное название, описание, актеров, режиссеров, жанры, страны и другие связи каталога.' : null,
            $activeTaxonomies->isNotEmpty() ? 'Активные фильтры: '.$activeTaxonomies->pluck('name')->implode(', ').'.' : null,
        ])->filter()->implode(' ');

        return [
            $parts !== '' ? $parts : 'Каталог сериалов поддерживает фильтрацию по жанрам, странам, актерам, режиссерам, годам, переводам, статусам, каналам, студиям и тегам.',
            'Все страницы формируются автоматически из актуальной базы: после обновления появляются постеры, описания, сезоны, серии, видео и SEO-данные.',
        ];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function catalogRelatedLinks(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $links = collect([
            ['name' => 'Все сериалы', 'url' => route('titles.index')],
            ['name' => 'Сериалы '.now()->year.' года', 'url' => route('titles.year', ['year' => now()->year])],
        ]);

        if ($year !== null) {
            $links->push(['name' => 'Сериалы '.($year - 1).' года', 'url' => route('titles.year', ['year' => $year - 1])]);
            $links->push(['name' => 'Сериалы '.($year + 1).' года', 'url' => route('titles.year', ['year' => $year + 1])]);
        }

        foreach ($activeTaxonomies as $filterType => $taxonomy) {
            $links->push([
                'name' => $this->filterTypeLabel($filterType).': '.$taxonomy->name,
                'url' => route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $taxonomy->slug]),
            ]);
        }

        if ($search !== '') {
            $links->push(['name' => 'Поиск: '.$search, 'url' => route('titles.index', ['q' => $search])]);
        }

        return $links
            ->unique('url')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function titleSeoText(
        CatalogTitle $catalogTitle,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $facts = collect([
            $catalogTitle->year ? 'год выхода '.$catalogTitle->year : null,
            $genres->isNotEmpty() ? 'жанры: '.$genres->take(5)->implode(', ') : null,
            $countries->isNotEmpty() ? 'страна: '.$countries->take(3)->implode(', ') : null,
            $directors->isNotEmpty() ? 'режиссеры: '.$directors->take(4)->implode(', ') : null,
            $actors->isNotEmpty() ? 'в ролях: '.$actors->take(8)->implode(', ') : null,
        ])->filter()->implode('; ');

        return [
            'Страница сериала '.$catalogTitle->title.' автоматически собирает описание, постер, сезоны, серии, связи каталога и доступные видео для просмотра онлайн.',
            ($facts !== '' ? 'Основная информация: '.$facts.'. ' : '').'Сейчас в базе указано сезонов: '.$seasonCount.', серий: '.$episodeCount.', видео-файлов: '.$mediaCount.'.',
            'SEO-данные этой страницы обновляются из импортированной информации: алиасы, оригинальное название, рейтинги, актеры, жанры, страны, сезоны и серии используются для формирования поисковых фраз.',
        ];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function titleRelatedLinks(CatalogTitle $catalogTitle, Collection $taxonomiesByType): array
    {
        $links = collect([
            ['name' => 'Все сериалы', 'url' => route('titles.index')],
        ]);

        if ($catalogTitle->year) {
            $links->push(['name' => 'Сериалы '.$catalogTitle->year.' года', 'url' => route('titles.year', ['year' => $catalogTitle->year])]);
        }

        foreach (['genre', 'country', 'actor', 'director', 'age_rating', 'translation'] as $type) {
            foreach ($taxonomiesByType->get($type, collect())->take(3) as $taxonomy) {
                $links->push([
                    'name' => $this->filterTypeLabel($type).': '.$taxonomy->name,
                    'url' => route('titles.taxonomy', ['type' => $type, 'taxonomy' => $taxonomy->slug]),
                ]);
            }
        }

        return $links
            ->unique('url')
            ->take(14)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function catalogSearchPhrases(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $base = collect([
            'смотреть сериалы онлайн',
            'каталог сериалов онлайн',
            'сериалы по жанрам',
            'сериалы по странам',
            'сериалы по актерам',
        ]);

        if ($search !== '') {
            $base = $base->merge([
                $search.' смотреть онлайн',
                'сериалы похожие на '.$search,
                $search.' все серии',
            ]);
        }

        if ($year !== null) {
            $base = $base->merge([
                'сериалы '.$year.' года',
                'смотреть сериалы '.$year.' онлайн',
                'новые сериалы '.$year,
            ]);
        }

        foreach ($activeTaxonomies as $taxonomy) {
            $base = $base->merge([
                'сериалы '.$taxonomy->name.' онлайн',
                'смотреть сериалы '.$taxonomy->name,
                'лучшие сериалы '.$taxonomy->name,
            ]);
        }

        return $base
            ->filter()
            ->map(fn (string $phrase): string => Str::lower($phrase))
            ->unique()
            ->take(16)
            ->values()
            ->all();
    }

    private function catalogSeoHeading(string $search, ?int $year, Collection $activeTaxonomies): string
    {
        if ($search !== '') {
            return 'Сериалы по запросу '.$search;
        }

        if ($activeTaxonomies->isNotEmpty() && $year !== null) {
            return 'Сериалы '.$year.' года: '.$activeTaxonomies->pluck('name')->implode(', ');
        }

        if ($activeTaxonomies->isNotEmpty()) {
            return 'Сериалы: '.$activeTaxonomies->pluck('name')->implode(', ');
        }

        if ($year !== null) {
            return 'Сериалы '.$year.' года смотреть онлайн';
        }

        return 'Все сериалы онлайн';
    }

    private function catalogSeoLead(int $total, string $search, ?int $year, Collection $activeTaxonomies): string
    {
        $parts = collect([
            $total.' сериалов в подборке',
            $year !== null ? 'год '.$year : null,
            $search !== '' ? 'поиск: '.$search : null,
            $activeTaxonomies->isNotEmpty() ? 'фильтры: '.$activeTaxonomies->pluck('name')->implode(', ') : null,
        ])->filter()->implode(', ');

        return ucfirst($parts).'. Автоматическая выдача учитывает названия, описания, жанры, страны, актеров, режиссеров, сезоны, серии и доступное видео.';
    }

    private function titleKeywordCollection(
        CatalogTitle $catalogTitle,
        Collection $alternateNames,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): Collection {
        $baseNames = collect([$catalogTitle->title, $catalogTitle->original_title])
            ->merge($alternateNames)
            ->filter()
            ->unique()
            ->values();
        $keywords = collect([
            $catalogTitle->title,
            $catalogTitle->original_title,
            'сериал '.$catalogTitle->title,
            'сериал '.$catalogTitle->title.' смотреть онлайн',
            $catalogTitle->title.' онлайн',
            $catalogTitle->title.' все серии',
            $catalogTitle->title.' все сезоны',
            $catalogTitle->title.' в хорошем качестве',
            $catalogTitle->title.' плеер',
            $catalogTitle->year ? $catalogTitle->title.' '.$catalogTitle->year : null,
            $catalogTitle->year ? 'сериал '.$catalogTitle->title.' '.$catalogTitle->year.' смотреть онлайн' : null,
            $seasonCount > 0 ? $catalogTitle->title.' '.$seasonCount.' сезон' : null,
            $episodeCount > 0 ? $catalogTitle->title.' '.$episodeCount.' серий' : null,
            $mediaCount > 0 ? $catalogTitle->title.' смотреть в плеере' : null,
        ])
            ->merge($baseNames)
            ->merge($genres)
            ->merge($countries)
            ->merge($actors)
            ->merge($directors);

        foreach ($baseNames->take(5) as $name) {
            $keywords = $keywords->merge([
                $name.' смотреть онлайн',
                $name.' все серии онлайн',
                $name.' сезоны и серии',
            ]);
        }

        foreach ($genres->take(5) as $genre) {
            $keywords = $keywords->merge([
                $catalogTitle->title.' '.$genre,
                'сериалы '.$genre.' онлайн',
            ]);
        }

        foreach ($countries->take(3) as $country) {
            $keywords = $keywords->merge([
                $catalogTitle->title.' '.$country,
                'сериалы '.$country.' смотреть онлайн',
            ]);
        }

        foreach ($actors->take(5) as $actor) {
            $keywords = $keywords->push($catalogTitle->title.' '.$actor);
        }

        return $keywords
            ->filter()
            ->map(fn (string $keyword): string => trim(preg_replace('/\s+/u', ' ', $keyword) ?: $keyword))
            ->filter(fn (string $keyword): bool => $keyword !== '' && mb_strlen($keyword) <= 120)
            ->unique(fn (string $keyword): string => Str::lower($keyword))
            ->values();
    }

    /**
     * @return list<string>
     */
    private function titleSearchPhrases(
        CatalogTitle $catalogTitle,
        Collection $keywords,
        Collection $genres,
        Collection $countries,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $phrases = collect([
            'смотреть '.$catalogTitle->title.' онлайн',
            $catalogTitle->title.' все серии подряд',
            $catalogTitle->title.' все сезоны онлайн',
            $catalogTitle->title.' бесплатно в плеере',
            $catalogTitle->title.' описание сериала',
            $catalogTitle->title.' актеры и роли',
            $catalogTitle->title.' жанр и страна',
            $catalogTitle->year ? $catalogTitle->title.' '.$catalogTitle->year.' онлайн' : null,
            $seasonCount > 0 ? $catalogTitle->title.' '.$seasonCount.' сезон смотреть' : null,
            $episodeCount > 0 ? $catalogTitle->title.' '.$episodeCount.' серий смотреть' : null,
            $mediaCount > 0 ? $catalogTitle->title.' видео онлайн' : null,
        ])
            ->merge($genres->take(3)->map(fn (string $genre): string => $catalogTitle->title.' '.$genre.' сериал'))
            ->merge($countries->take(2)->map(fn (string $country): string => $catalogTitle->title.' '.$country.' сериал'))
            ->merge($keywords->take(8));

        return $phrases
            ->filter()
            ->map(fn (string $phrase): string => trim(preg_replace('/\s+/u', ' ', $phrase) ?: $phrase))
            ->filter(fn (string $phrase): bool => $phrase !== '' && mb_strlen($phrase) <= 140)
            ->unique(fn (string $phrase): string => Str::lower($phrase))
            ->take(18)
            ->values()
            ->all();
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function titleFaqItems(
        CatalogTitle $catalogTitle,
        Collection $genres,
        Collection $countries,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $genreText = $genres->take(5)->implode(', ');
        $countryText = $countries->take(3)->implode(', ');

        return collect([
            [
                'question' => 'Где смотреть сериал '.$catalogTitle->title.' онлайн?',
                'answer' => $mediaCount > 0
                    ? 'Сериал '.$catalogTitle->title.' доступен на этой странице во встроенном плеере. Видео открывается через выбранную серию.'
                    : 'Страница сериала '.$catalogTitle->title.' уже создана, а видео появится здесь после ближайшего обновления.',
            ],
            [
                'question' => 'Сколько сезонов и серий в сериале '.$catalogTitle->title.'?',
                'answer' => 'В каталоге сейчас указано сезонов: '.$seasonCount.', серий: '.$episodeCount.'. Эти данные обновляются автоматически после обработки источника.',
            ],
            [
                'question' => 'Какой год, жанр и страна у сериала '.$catalogTitle->title.'?',
                'answer' => collect([
                    $catalogTitle->year ? 'Год выхода: '.$catalogTitle->year : null,
                    $genreText !== '' ? 'жанры: '.$genreText : null,
                    $countryText !== '' ? 'страна: '.$countryText : null,
                ])->filter()->implode('; ').'.',
            ],
            [
                'question' => 'Обновляется ли информация по сериалу '.$catalogTitle->title.'?',
                'answer' => 'Да, портал автоматически обновляет описание, сезоны, серии, постер, связи каталога и доступные видео.',
            ],
        ])
            ->filter(fn (array $item): bool => trim($item['answer'], " .\t\n\r\0\x0B") !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{question: string, answer: string}>  $items
     * @return array<string, mixed>
     */
    private function faqJsonLd(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => collect($items)
                ->map(fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seasonJsonLd(CatalogTitle $catalogTitle, Collection $seasons): array
    {
        return $seasons
            ->values()
            ->map(fn ($season): array => $this->withoutEmpty([
                '@type' => 'CreativeWorkSeason',
                'name' => 'Сезон '.$season->number,
                'seasonNumber' => (int) $season->number,
                'numberOfEpisodes' => $season->episodes->count(),
                'url' => route('titles.show', $catalogTitle).'#season-'.$season->number,
            ]))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeItemListJsonLd(CatalogTitle $catalogTitle, Collection $seasons): array
    {
        $episodes = $seasons
            ->flatMap(fn ($season): Collection => $season->episodes
                ->sortBy('number')
                ->values()
                ->map(fn ($episode): array => [
                    'season' => $season,
                    'episode' => $episode,
                ]))
            ->values()
            ->take(100);

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Серии '.$catalogTitle->title,
            'numberOfItems' => $episodes->count(),
            'itemListElement' => $episodes
                ->values()
                ->map(fn (array $item, int $index): array => $this->withoutEmpty([
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => $this->withoutEmpty([
                        '@type' => 'TVEpisode',
                        'name' => $item['episode']->title ?: $item['episode']->number.' серия',
                        'episodeNumber' => (int) $item['episode']->number,
                        'partOfSeason' => [
                            '@type' => 'CreativeWorkSeason',
                            'seasonNumber' => (int) $item['season']->number,
                        ],
                        'partOfSeries' => [
                            '@type' => 'TVSeries',
                            'name' => $catalogTitle->title,
                        ],
                        'url' => route('titles.show', ['catalogTitle' => $catalogTitle, 'episode' => $item['episode']->id]).'#player',
                    ]),
                ]))
                ->all(),
        ];
    }

    /**
     * @param  list<array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    private function breadcrumbJsonLd(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)
                ->values()
                ->map(fn (array $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'item' => $item['url'],
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $data): array
    {
        return array_filter($data, fn (mixed $value): bool => ! ($value === null || $value === '' || $value === []));
    }

    /**
     * @return list<array{title: string, items: list<string>}>
     */
    private function catalogKeywordClusters(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $taxonomyNames = $activeTaxonomies->pluck('name')->filter()->values();

        return [
            [
                'title' => 'Смотреть онлайн',
                'items' => collect(['смотреть сериалы онлайн', 'сериалы онлайн в хорошем качестве', 'все серии сериалов онлайн', 'все сезоны сериалов'])
                    ->when($search !== '', fn (Collection $items): Collection => $items->push($search.' смотреть онлайн'))
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Подборки',
                'items' => collect(['сериалы по жанрам', 'сериалы по странам', 'сериалы по актерам', 'сериалы по режиссерам'])
                    ->merge($taxonomyNames->map(fn (string $name): string => 'сериалы '.$name.' онлайн'))
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Годы и обновления',
                'items' => collect(['новые сериалы', 'обновления сериалов', 'новые серии'])
                    ->when($year !== null, fn (Collection $items): Collection => $items->push('сериалы '.$year.' года')->push('смотреть сериалы '.$year.' онлайн'))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return list<array{title: string, items: list<string>}>
     */
    private function titleKeywordClusters(
        CatalogTitle $catalogTitle,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        return [
            [
                'title' => 'Смотреть онлайн',
                'items' => collect([
                    $catalogTitle->title.' смотреть онлайн',
                    $catalogTitle->title.' все серии',
                    $catalogTitle->title.' все сезоны',
                    $mediaCount > 0 ? $catalogTitle->title.' смотреть в плеере' : $catalogTitle->title.' видео скоро появится',
                ])->filter()->values()->all(),
            ],
            [
                'title' => 'Информация о сериале',
                'items' => collect([
                    $catalogTitle->year ? $catalogTitle->title.' '.$catalogTitle->year : null,
                    $seasonCount > 0 ? $catalogTitle->title.' '.$seasonCount.' сезон' : null,
                    $episodeCount > 0 ? $catalogTitle->title.' '.$episodeCount.' серий' : null,
                    $genres->isNotEmpty() ? $catalogTitle->title.' '.$genres->first() : null,
                    $countries->isNotEmpty() ? $catalogTitle->title.' '.$countries->first() : null,
                ])->filter()->values()->all(),
            ],
            [
                'title' => 'Люди и связи',
                'items' => collect()
                    ->merge($actors->take(5)->map(fn (string $actor): string => $catalogTitle->title.' '.$actor))
                    ->merge($directors->take(3)->map(fn (string $director): string => $catalogTitle->title.' режиссер '.$director))
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function sitemapDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toAtomString();
            } catch (\Throwable) {
                return now()->toAtomString();
            }
        }

        return now()->toAtomString();
    }
}
