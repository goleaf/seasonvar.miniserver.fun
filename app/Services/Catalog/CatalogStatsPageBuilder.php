<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\SeasonvarPageType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class CatalogStatsPageBuilder
{
    private const TITLE_SITEMAP_PAGE_SIZE = 10000;

    private const VIDEO_SITEMAP_PAGE_SIZE = 5000;

    private const STATS_POSTER_CANDIDATE_LIMIT = 32;

    /**
     * @var array<string, array{table: string, pivot: string, related_key: string}>
     */
    private const FILTER_LINKS = [
        'genre' => ['table' => 'genres', 'pivot' => 'catalog_title_genre', 'related_key' => 'genre_id'],
        'country' => ['table' => 'countries', 'pivot' => 'catalog_title_country', 'related_key' => 'country_id'],
        'actor' => ['table' => 'actors', 'pivot' => 'catalog_title_actor', 'related_key' => 'actor_id'],
        'director' => ['table' => 'directors', 'pivot' => 'catalog_title_director', 'related_key' => 'director_id'],
        'age_rating' => ['table' => 'age_ratings', 'pivot' => 'age_rating_catalog_title', 'related_key' => 'age_rating_id'],
        'translation' => ['table' => 'translations', 'pivot' => 'catalog_title_translation', 'related_key' => 'translation_id'],
        'status' => ['table' => 'catalog_statuses', 'pivot' => 'catalog_status_catalog_title', 'related_key' => 'catalog_status_id'],
        'network' => ['table' => 'networks', 'pivot' => 'catalog_title_network', 'related_key' => 'network_id'],
        'studio' => ['table' => 'studios', 'pivot' => 'catalog_title_studio', 'related_key' => 'studio_id'],
        'tag' => ['table' => 'tags', 'pivot' => 'catalog_title_tag', 'related_key' => 'tag_id'],
    ];

    /**
     * @var list<string>
     */
    private const LANDING_FILTER_TYPES = ['genre', 'country', 'actor', 'director', 'translation', 'age_rating'];

    /**
     * @var array<string, int>
     */
    private array $tableCounts = [];

    /**
     * @var array<string, int>
     */
    private array $presentCounts = [];

    /**
     * @var array<string, int>
     */
    private array $distinctPresentCounts = [];

    /**
     * @var array<string, int>
     */
    private array $absoluteUrlCounts = [];

    /**
     * @var Collection<int, array{table: string, name: string, unique: bool, origin: string, partial: bool, columns: string}>|null
     */
    private ?Collection $databaseIndexes = null;

    public function __construct(
        private readonly CatalogStatsPosterUrlGuard $posterUrls,
        private readonly CatalogTitleQuery $titles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $databaseTables = $this->databaseTables();
        $catalogTitles = $this->tableCount('catalog_titles');
        $seasons = $this->tableCount('seasons');
        $episodes = $this->tableCount('episodes');
        $media = $this->tableCount('licensed_media');
        $sourcePages = $this->tableCount('source_pages');
        $publishedMedia = $this->whereCount('licensed_media', fn (QueryBuilder $query): QueryBuilder => $query->where('status', 'published'));
        $publishedTitles = $this->titles->visibleTo(null)->count();
        $totalDatabaseRows = $databaseTables->sum('total');
        $pageStats = $this->pageStats();
        $databaseOptimization = $this->databaseOptimizationStats($databaseTables);
        $qualitySections = $this->qualitySections($catalogTitles, $seasons, $episodes, $media, $sourcePages);
        $recentImportRuns = $this->recentImportRuns();

        return [
            'statsHealthCards' => $this->statsHealthCards($catalogTitles, $media, $publishedMedia, $databaseOptimization, $recentImportRuns),
            'statsPosterRows' => $this->statsPosterRows(),
            'statsIssueRows' => $this->statsIssueRows(),
            'qualityProgressRows' => $this->qualityProgressRows($qualitySections),
            'headlineStats' => [
                ['label' => 'Записей в базе', 'value' => $totalDatabaseRows, 'icon' => 'fa-solid fa-database'],
                ['label' => 'Сериалов каталога', 'value' => $catalogTitles, 'icon' => 'fa-solid fa-clapperboard'],
                ['label' => 'Сезонов и серий', 'value' => $seasons + $episodes, 'icon' => 'fa-solid fa-layer-group'],
                ['label' => 'Видео-ссылок', 'value' => $media, 'icon' => 'fa-solid fa-circle-play'],
                ['label' => 'Публичных адресов', 'value' => $pageStats['totals']['public_urls'], 'icon' => 'fa-solid fa-link'],
                ['label' => 'Полей со ссылками', 'value' => $pageStats['totals']['external_url_fields'], 'icon' => 'fa-solid fa-table-list'],
                ['label' => 'Индексов базы', 'value' => $databaseOptimization['totals']['indexes'], 'icon' => 'fa-solid fa-gauge-high'],
                ['label' => 'Проверок индексов', 'value' => $databaseOptimization['totals']['expected_indexes'], 'icon' => 'fa-solid fa-magnifying-glass-chart'],
            ],
            'summarySections' => [
                [
                    'title' => 'Каталог',
                    'icon' => 'fa-solid fa-clapperboard',
                    'rows' => [
                        $this->row('Сериалов всего', $catalogTitles),
                        $this->row('Опубликовано', $publishedTitles, $this->percent($publishedTitles, $catalogTitles)),
                        $this->row('С годом выхода', $this->presentCount('catalog_titles', 'year'), $this->percent($this->presentCount('catalog_titles', 'year'), $catalogTitles)),
                        $this->row('С описанием', $this->presentCount('catalog_titles', 'description'), $this->percent($this->presentCount('catalog_titles', 'description'), $catalogTitles)),
                        $this->row('С постером', $this->presentCount('catalog_titles', 'poster_url'), $this->percent($this->presentCount('catalog_titles', 'poster_url'), $catalogTitles)),
                        $this->row('С оригинальным названием', $this->presentCount('catalog_titles', 'original_title'), $this->percent($this->presentCount('catalog_titles', 'original_title'), $catalogTitles)),
                        $this->row('С внешним номером', $this->presentCount('catalog_titles', 'external_id'), $this->percent($this->presentCount('catalog_titles', 'external_id'), $catalogTitles)),
                        $this->row('Без опубликованного видео', CatalogTitle::query()->whereDoesntHave('licensedMedia', fn (EloquentBuilder $query): EloquentBuilder => $query->where('status', 'published'))->count()),
                    ],
                ],
                [
                    'title' => 'Сезоны и серии',
                    'icon' => 'fa-solid fa-list-ol',
                    'rows' => [
                        $this->row('Сезонов', $seasons),
                        $this->row('Серий', $episodes),
                        $this->row('Среднее сезонов на сериал', $this->average($seasons, $catalogTitles)),
                        $this->row('Среднее серий на сезон', $this->average($episodes, $seasons)),
                        $this->row('Среднее серий на сериал', $this->average($episodes, $catalogTitles)),
                        $this->row('Сезонов с общим количеством серий', $this->presentCount('seasons', 'episodes_total'), $this->percent($this->presentCount('seasons', 'episodes_total'), $seasons)),
                        $this->row('Сезонов с количеством вышедших серий', $this->presentCount('seasons', 'episodes_released'), $this->percent($this->presentCount('seasons', 'episodes_released'), $seasons)),
                        $this->row('Серий с датой выхода', $this->presentCount('episodes', 'released_at'), $this->percent($this->presentCount('episodes', 'released_at'), $episodes)),
                        $this->row('Серий без опубликованного видео', Episode::query()->whereDoesntHave('licensedMedia', fn (EloquentBuilder $query): EloquentBuilder => $query->where('status', 'published'))->count()),
                    ],
                ],
                [
                    'title' => 'Видео',
                    'icon' => 'fa-solid fa-photo-film',
                    'rows' => [
                        $this->row('Видео-ссылок всего', $media),
                        $this->row('Готово к просмотру', $publishedMedia, $this->percent($publishedMedia, $media)),
                        $this->row('Связано с сериалом', $this->presentCount('licensed_media', 'catalog_title_id'), $this->percent($this->presentCount('licensed_media', 'catalog_title_id'), $media)),
                        $this->row('Связано с сезоном', $this->presentCount('licensed_media', 'season_id'), $this->percent($this->presentCount('licensed_media', 'season_id'), $media)),
                        $this->row('Связано с серией', $this->presentCount('licensed_media', 'episode_id'), $this->percent($this->presentCount('licensed_media', 'episode_id'), $media)),
                        $this->row('С качеством', $this->presentCount('licensed_media', 'quality'), $this->percent($this->presentCount('licensed_media', 'quality'), $media)),
                        $this->row('С форматом', $this->presentCount('licensed_media', 'format'), $this->percent($this->presentCount('licensed_media', 'format'), $media)),
                        $this->row('С постоянным ключом', $this->presentCount('licensed_media', 'source_media_key'), $this->percent($this->presentCount('licensed_media', 'source_media_key'), $media)),
                        $this->row('Проверено для просмотра', $this->presentCount('licensed_media', 'checked_at'), $this->percent($this->presentCount('licensed_media', 'checked_at'), $media)),
                    ],
                ],
                [
                    'title' => 'Обновление каталога',
                    'icon' => 'fa-solid fa-rotate',
                    'rows' => $this->sourceAndImportRows($sourcePages),
                ],
                [
                    'title' => 'Оценки, отзывы, названия',
                    'icon' => 'fa-solid fa-star-half-stroke',
                    'rows' => [
                        $this->row('Альтернативных названий', $this->tableCount('catalog_title_aliases')),
                        $this->row('Оценок', $this->tableCount('catalog_title_ratings')),
                        $this->row('Отзывов', $this->tableCount('catalog_title_reviews')),
                        $this->row('Отзывов с автором', $this->presentCount('catalog_title_reviews', 'author'), $this->percent($this->presentCount('catalog_title_reviews', 'author'), $this->tableCount('catalog_title_reviews'))),
                        $this->row('Отзывов с датой публикации', $this->presentCount('catalog_title_reviews', 'published_at'), $this->percent($this->presentCount('catalog_title_reviews', 'published_at'), $this->tableCount('catalog_title_reviews'))),
                    ],
                ],
                [
                    'title' => 'Когда обновлялось',
                    'icon' => 'fa-solid fa-clock-rotate-left',
                    'rows' => $this->freshnessRows(),
                ],
            ],
            'qualitySections' => $qualitySections,
            'timeWindowRows' => $this->timeWindowRows(),
            'recentImportRuns' => $recentImportRuns,
            'pageStatsSections' => $pageStats['summary_sections'],
            'routeRows' => $pageStats['route_rows'],
            'internalLinkRows' => $pageStats['internal_link_rows'],
            'externalUrlFieldRows' => $pageStats['external_url_field_rows'],
            'databaseOptimizationSections' => $databaseOptimization['summary_sections'],
            'databaseExpectedIndexRows' => $databaseOptimization['expected_index_rows'],
            'databaseIndexRows' => $databaseOptimization['index_rows'],
            'databaseOptimizationIssueRows' => $databaseOptimization['issue_rows'],
            'groupSections' => $this->groupSections(),
            'taxonomyRows' => $this->taxonomyRows(),
            'databaseTables' => $databaseTables,
            'seo' => $this->seo(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function seo(): array
    {
        return [
            'title' => 'Сводка каталога',
            'description' => 'Сводка каталога: сериалы, сезоны, серии, видео, отзывы, оценки, справочники и обновления.',
            'canonical' => route('stats'),
            'robots' => 'noindex,nofollow',
            'extended_seo' => false,
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => route('home')],
                ['name' => 'Сводка каталога', 'url' => route('stats')],
            ],
        ];
    }

    /**
     * @return array{
     *     totals: array{public_urls: int, external_url_fields: int},
     *     summary_sections: list<array{title: string, icon: string, rows: list<array{label: string, value: mixed, display: string, meta: string|null}>}>,
     *     route_rows: list<array{name: string, uri: string, label: string, address: string, kind: string, scope: string, generated_count: int, generated_display: string}>,
     *     internal_link_rows: Collection<int, array{label: string, place: string, route: string, count: int, count_display: string, meta: string}>,
     *     external_url_field_rows: Collection<int, array{label: string, field: string, total_display: string, filled_display: string, unique_display: string, absolute_display: string, empty_display: string, coverage: string}>
     * }
     */
    private function pageStats(): array
    {
        $publishedTitleUrls = $this->publishedTitleUrlCount();
        $yearUrls = $this->publishedYearUrlCount();
        $taxonomyUrls = $this->publishedTaxonomyUrlCount();
        $landingUrls = $this->landingUrlCount();
        $videoPlayerLinks = $this->publishedVideoUrlCount();
        $episodePlayerLinks = $this->episodePlayerLinkCount();
        $titleSitemapPages = $this->paginatedPageCount($publishedTitleUrls, self::TITLE_SITEMAP_PAGE_SIZE);
        $videoSitemapPages = $this->paginatedPageCount($videoPlayerLinks, self::VIDEO_SITEMAP_PAGE_SIZE);
        $sitemapDocuments = 5 + $titleSitemapPages + $videoSitemapPages;
        $machineReadableEndpoints = $sitemapDocuments + 3;
        $htmlPages = 3 + $yearUrls + $taxonomyUrls + $publishedTitleUrls;
        $queryAndFragmentLinks = $landingUrls + $videoPlayerLinks + $episodePlayerLinks + 1;
        $publicUrls = $htmlPages + $queryAndFragmentLinks + $machineReadableEndpoints;
        $namedGetRoutes = $this->namedGetRoutes();
        $parameterizedRoutes = $namedGetRoutes->filter(fn (RouteDefinition $route): bool => str_contains($route->uri(), '{'))->count();
        $externalUrlFieldRows = $this->externalUrlFieldRows();

        $routeUrlCounts = [
            'home' => 1,
            'stats' => 1,
            'stats.poster' => $this->presentCount('catalog_titles', 'poster_url'),
            'titles.index' => 1,
            'titles.year' => $yearUrls,
            'titles.taxonomy' => $taxonomyUrls,
            'titles.show' => $publishedTitleUrls,
            'sitemap' => 1,
            'sitemap.index' => 1,
            'sitemap.static' => 1,
            'sitemap.taxonomies' => 1,
            'sitemap.landings' => 1,
            'sitemap.titles' => $titleSitemapPages,
            'sitemap.videos' => $videoSitemapPages,
            'feed' => 1,
            'opensearch' => 1,
            'llms' => 1,
        ];

        return [
            'totals' => [
                'public_urls' => $publicUrls,
                'external_url_fields' => $externalUrlFieldRows->count(),
            ],
            'summary_sections' => [
                [
                    'title' => 'Страницы и ссылки',
                    'icon' => 'fa-solid fa-link',
                    'rows' => [
                        $this->row('Публичных адресов всего', $publicUrls),
                        $this->row('Страниц каталога', $htmlPages),
                        $this->row('Ссылок с выбором', $queryAndFragmentLinks),
                        $this->row('Служебных файлов', $machineReadableEndpoints),
                        $this->row('Разделы портала', $namedGetRoutes->count()),
                        $this->row('Разделов с выбором', $parameterizedRoutes),
                    ],
                ],
                [
                    'title' => 'Покрытие карты сайта',
                    'icon' => 'fa-solid fa-sitemap',
                    'rows' => [
                        $this->row('Файлов карты сайта', $sitemapDocuments),
                        $this->row('Файлов карты с сериалами', $titleSitemapPages, self::TITLE_SITEMAP_PAGE_SIZE.' адресов в файле'),
                        $this->row('Файлов карты с видео', $videoSitemapPages, self::VIDEO_SITEMAP_PAGE_SIZE.' адресов в файле'),
                        $this->row('Сериалов в карте сайта', $publishedTitleUrls),
                        $this->row('Видео в карте сайта', $videoPlayerLinks),
                        $this->row('Страниц справочников по годам', $landingUrls),
                    ],
                ],
            ],
            'route_rows' => $this->routeRows($routeUrlCounts),
            'internal_link_rows' => $this->internalLinkRows($publishedTitleUrls, $yearUrls, $taxonomyUrls, $landingUrls, $videoPlayerLinks, $episodePlayerLinks, $sitemapDocuments),
            'external_url_field_rows' => $externalUrlFieldRows,
        ];
    }

    /**
     * @param  array{totals: array{indexes: int, expected_indexes: int, missing_expected_indexes: int}}  $databaseOptimization
     * @param  list<array{id: string, mode: string, status: string, status_class: string, status_tone: string, options: list<string>, cycles: string, discovery: string, discovery_meta: string, pages: string, pages_meta: string, failed: string, media: string, media_meta: string, media_extra: string, maintenance: list<string>, started_at: string, finished_at: string, duration: string}>  $recentImportRuns
     * @return list<array{label: string, value: string, meta: string, icon: string, tone: string}>
     */
    private function statsHealthCards(int $catalogTitles, int $media, int $publishedMedia, array $databaseOptimization, array $recentImportRuns): array
    {
        $latestRun = $recentImportRuns[0] ?? null;
        $posters = $this->presentCount('catalog_titles', 'poster_url');
        $failedPages = $this->whereCount('source_pages', fn (QueryBuilder $query): QueryBuilder => $query
            ->where('parse_status', 'failed')
            ->orWhere('import_status', 'failed'));
        $missingIndexes = $databaseOptimization['totals']['missing_expected_indexes'];
        $expectedIndexes = $databaseOptimization['totals']['expected_indexes'];

        return [
            $this->healthCard(
                'Последний запуск',
                $latestRun['id'] ?? 'нет данных',
                $latestRun === null ? 'обновление еще не запускалось' : $latestRun['mode'].' / '.$latestRun['status'],
                'fa-solid fa-clock-rotate-left',
                $latestRun['status_tone'] ?? 'muted',
            ),
            $this->healthCard(
                'Готовность видео',
                $this->percent($publishedMedia, $media),
                $this->formatStat($publishedMedia).' / '.$this->formatStat($media).' готовы к просмотру',
                'fa-solid fa-circle-play',
                $publishedMedia === 0 && $media > 0 ? 'warning' : 'success',
            ),
            $this->healthCard(
                'Постеры сериалов',
                $this->percent($posters, $catalogTitles),
                $this->formatStat($posters).' / '.$this->formatStat($catalogTitles).' с картинками',
                'fa-regular fa-image',
                $posters === 0 && $catalogTitles > 0 ? 'warning' : 'sky',
            ),
            $this->healthCard(
                'Ошибки страниц',
                $this->formatStat($failedPages),
                'ошибки сбора или обновления',
                'fa-solid fa-triangle-exclamation',
                $failedPages > 0 ? 'danger' : 'success',
            ),
            $this->healthCard(
                'Важные индексы',
                $missingIndexes > 0 ? $this->formatStat($missingIndexes) : 'на месте',
                $this->formatStat($expectedIndexes).' проверок индексов',
                'fa-solid fa-magnifying-glass-chart',
                $missingIndexes > 0 ? 'danger' : 'success',
            ),
            $this->healthCard(
                'Новые за 24 часа',
                $this->formatStat($this->sinceCount('catalog_titles', 'created_at', now()->subDay())),
                'сериалы, добавленные недавно',
                'fa-solid fa-calendar-day',
                'slate',
            ),
        ];
    }

    /**
     * @return array{label: string, value: string, meta: string, icon: string, tone: string}
     */
    private function healthCard(string $label, string $value, string $meta, string $icon, string $tone): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'meta' => $meta,
            'icon' => $icon,
            'tone' => $tone,
        ];
    }

    /**
     * @return list<array{id: int, title: string, original_title: string|null, year: string, label: string, meta: string, href: string, poster_src: string|null, icon: string, tone: string}>
     */
    private function statsPosterRows(): array
    {
        return $this->titles->visibleTo(null)
            ->select(['id', 'slug', 'title', 'original_title', 'year', 'poster_url', 'indexed_at'])
            ->whereNotNull('poster_url')
            ->where('poster_url', '!=', '')
            ->latest('indexed_at')
            ->latest('id')
            ->limit(self::STATS_POSTER_CANDIDATE_LIMIT)
            ->get()
            ->filter(fn (CatalogTitle $title): bool => $this->posterUrls->safeUrl($title->poster_url) !== null)
            ->take(8)
            ->map(fn (CatalogTitle $title): array => $this->titlePreviewRow(
                $title,
                'Обновлена',
                $this->dateValue($title->indexed_at),
                'fa-solid fa-clock',
                'sky',
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, title: string, original_title: string|null, year: string, label: string, meta: string, href: string, poster_src: string|null, icon: string, tone: string}>
     */
    private function statsIssueRows(): array
    {
        $withoutPublishedMedia = $this->titles->visibleTo(null)
            ->select(['id', 'slug', 'title', 'original_title', 'year', 'poster_url', 'indexed_at'])
            ->whereDoesntHave('licensedMedia', fn (EloquentBuilder $query): EloquentBuilder => $query->where('status', 'published'))
            ->latest('indexed_at')
            ->limit(4)
            ->get()
            ->map(fn (CatalogTitle $title): array => $this->titlePreviewRow($title, 'Без видео', 'нет опубликованного видео', 'fa-solid fa-circle-play', 'danger'));

        $withoutPoster = $this->titles->visibleTo(null)
            ->select(['id', 'slug', 'title', 'original_title', 'year', 'poster_url', 'indexed_at'])
            ->where(function (EloquentBuilder $query): void {
                $query->whereNull('poster_url')->orWhere('poster_url', '');
            })
            ->latest('indexed_at')
            ->limit(4)
            ->get()
            ->map(fn (CatalogTitle $title): array => $this->titlePreviewRow($title, 'Без постера', 'картинка не найдена', 'fa-regular fa-image', 'warning'));

        $withoutDescription = $this->titles->visibleTo(null)
            ->select(['id', 'slug', 'title', 'original_title', 'year', 'poster_url', 'indexed_at'])
            ->where(function (EloquentBuilder $query): void {
                $query->whereNull('description')->orWhere('description', '');
            })
            ->latest('indexed_at')
            ->limit(4)
            ->get()
            ->map(fn (CatalogTitle $title): array => $this->titlePreviewRow($title, 'Без описания', 'текст описания пустой', 'fa-solid fa-file-lines', 'warning'));

        return collect()
            ->merge($withoutPublishedMedia)
            ->merge($withoutPoster)
            ->merge($withoutDescription)
            ->unique('id')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, title: string, original_title: string|null, year: string, label: string, meta: string, href: string, poster_src: string|null, icon: string, tone: string}
     */
    private function titlePreviewRow(CatalogTitle $title, string $label, string $meta, string $icon, string $tone): array
    {
        $hasProxyablePoster = $this->posterUrls->safeUrl($title->poster_url) !== null;

        return [
            'id' => (int) $title->id,
            'title' => $title->display_title,
            'original_title' => $title->display_original_title,
            'year' => $title->year === null ? 'год не указан' : (string) $title->year,
            'label' => $label,
            'meta' => $meta,
            'href' => route('titles.show', $title),
            'poster_src' => $hasProxyablePoster ? route('stats.poster', $title) : null,
            'icon' => $icon,
            'tone' => $tone,
        ];
    }

    /**
     * @param  list<array{title: string, icon: string, rows: list<array{label: string, value: int, total: int, display: string, meta: string, percent_value: float, severity: string, severity_label: string, row_class: string, value_class: string}>}>  $qualitySections
     * @return Collection<int, array{label: string, display: string, meta: string, percent_value: float, severity: string, severity_label: string}>
     */
    private function qualityProgressRows(array $qualitySections): Collection
    {
        return collect($qualitySections)
            ->flatMap(fn (array $section): array => $section['rows'])
            ->filter(fn (array $row): bool => $row['value'] > 0)
            ->sortBy(fn (array $row): int => match ($row['severity']) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            })
            ->take(8)
            ->values()
            ->map(fn (array $row): array => [
                'label' => $row['label'],
                'display' => $row['display'],
                'meta' => $row['meta'],
                'percent_value' => $row['percent_value'],
                'severity' => $row['severity'],
                'severity_label' => $row['severity_label'],
            ]);
    }

    /**
     * @return list<array{title: string, icon: string, rows: list<array{label: string, value: int, total: int, display: string, meta: string, percent_value: float, severity: string, severity_label: string, row_class: string, value_class: string}>}>
     */
    private function qualitySections(int $catalogTitles, int $seasons, int $episodes, int $media, int $sourcePages): array
    {
        return [
            [
                'title' => 'Качество сериалов',
                'icon' => 'fa-solid fa-clipboard-check',
                'rows' => [
                    $this->qualityRow('Без опубликованного видео', CatalogTitle::query()->whereDoesntHave('licensedMedia', fn (EloquentBuilder $query): EloquentBuilder => $query->where('status', 'published'))->count(), $catalogTitles, 'critical'),
                    $this->qualityRow('Без сезонов', CatalogTitle::query()->whereDoesntHave('seasons')->count(), $catalogTitles, 'warning'),
                    $this->qualityRow('Без серий', CatalogTitle::query()->whereDoesntHave('episodes')->count(), $catalogTitles, 'warning'),
                    $this->qualityRow('Без постера', $this->missingCount('catalog_titles', 'poster_url'), $catalogTitles, 'warning'),
                    $this->qualityRow('Без описания', $this->missingCount('catalog_titles', 'description'), $catalogTitles, 'warning'),
                    $this->qualityRow('Без года', $this->missingCount('catalog_titles', 'year'), $catalogTitles, 'info'),
                    $this->qualityRow('Без жанров', $this->titlesMissingPivotCount('catalog_title_genre'), $catalogTitles, 'info'),
                    $this->qualityRow('Без стран', $this->titlesMissingPivotCount('catalog_title_country'), $catalogTitles, 'info'),
                ],
            ],
            [
                'title' => 'Качество сезонов и серий',
                'icon' => 'fa-solid fa-list-check',
                'rows' => [
                    $this->qualityRow('Сезоны без общего числа серий', $this->missingCount('seasons', 'episodes_total'), $seasons, 'info'),
                    $this->qualityRow('Сезоны без числа вышедших серий', $this->missingCount('seasons', 'episodes_released'), $seasons, 'info'),
                    $this->qualityRow('Серии без опубликованного видео', Episode::query()->whereDoesntHave('licensedMedia', fn (EloquentBuilder $query): EloquentBuilder => $query->where('status', 'published'))->count(), $episodes, 'warning'),
                    $this->qualityRow('Серии без даты выхода', $this->missingCount('episodes', 'released_at'), $episodes, 'info'),
                    $this->qualityRow('Серии без названия', $this->missingCount('episodes', 'title'), $episodes, 'info'),
                    $this->qualityRow('Серии без описания', $this->missingCount('episodes', 'summary'), $episodes, 'info'),
                ],
            ],
            [
                'title' => 'Качество медиа',
                'icon' => 'fa-solid fa-video',
                'rows' => [
                    $this->qualityRow('Не готовы к просмотру', $this->whereCount('licensed_media', fn (QueryBuilder $query): QueryBuilder => $query->where('status', '!=', 'published')), $media, 'critical'),
                    $this->qualityRow('Недоступны по проверке', $this->whereCount('licensed_media', fn (QueryBuilder $query): QueryBuilder => $query->whereIn('health_status', ['unavailable', 'disabled'])), $media, 'critical'),
                    $this->qualityRow('Без проверки доступности', $this->missingCount('licensed_media', 'checked_at'), $media, 'warning'),
                    $this->qualityRow('Без привязки к серии', $this->missingCount('licensed_media', 'episode_id'), $media, 'info'),
                    $this->qualityRow('Без качества', $this->missingCount('licensed_media', 'quality'), $media, 'info'),
                    $this->qualityRow('Без формата', $this->missingCount('licensed_media', 'format'), $media, 'info'),
                    $this->qualityRow('Без постоянного ключа', $this->missingCount('licensed_media', 'source_media_key'), $media, 'warning'),
                ],
            ],
            [
                'title' => 'Качество обновления',
                'icon' => 'fa-solid fa-rotate',
                'rows' => [
                    $this->qualityRow('Страницы с ошибкой сбора', $this->whereCount('source_pages', fn (QueryBuilder $query): QueryBuilder => $query->where('parse_status', 'failed')), $sourcePages, 'critical'),
                    $this->qualityRow('Страницы с ошибкой обновления', $this->whereCount('source_pages', fn (QueryBuilder $query): QueryBuilder => $query->where('import_status', 'failed')), $sourcePages, 'critical'),
                    $this->qualityRow('Ожидают повторной попытки', $this->whereCount('source_pages', fn (QueryBuilder $query): QueryBuilder => $query->whereNotNull('retry_after_at')), $sourcePages, 'warning'),
                    $this->qualityRow('С недостающими данными', $this->whereCount('source_pages', fn (QueryBuilder $query): QueryBuilder => $query->whereNotNull('missing_data_flags')), $sourcePages, 'warning'),
                    $this->qualityRow('Без контрольной суммы', $this->missingCount('source_pages', 'content_hash'), $sourcePages, 'info'),
                    $this->qualityRow('Не проверялись 30 дней', $this->staleSourcePagesCount(), $sourcePages, 'info'),
                ],
            ],
        ];
    }

    /**
     * @return list<array{label: string, catalog_titles: string, episodes: string, media: string, crawled: string, imported: string, import_errors: string}>
     */
    private function timeWindowRows(): array
    {
        return collect([
            ['label' => 'Сегодня', 'since' => Carbon::today()],
            ['label' => '24 часа', 'since' => now()->subDay()],
            ['label' => '7 дней', 'since' => now()->subDays(7)],
            ['label' => '30 дней', 'since' => now()->subDays(30)],
            ['label' => 'Все время', 'since' => null],
        ])->map(fn (array $window): array => [
            'label' => $window['label'],
            'catalog_titles' => $this->formatStat($this->sinceCount('catalog_titles', 'created_at', $window['since'])),
            'episodes' => $this->formatStat($this->sinceCount('episodes', 'created_at', $window['since'])),
            'media' => $this->formatStat($this->sinceCount('licensed_media', 'created_at', $window['since'])),
            'crawled' => $this->formatStat($this->sinceCount('source_pages', 'last_crawled_at', $window['since'])),
            'imported' => $this->formatStat($this->sinceCount('source_pages', 'last_imported_at', $window['since'])),
            'import_errors' => $this->formatStat($this->sinceCount('seasonvar_import_events', 'created_at', $window['since'], fn (QueryBuilder $query): QueryBuilder => $query->where('level', 'error'))),
        ])->all();
    }

    /**
     * @return list<array{id: string, mode: string, status: string, status_class: string, status_tone: string, options: list<string>, cycles: string, discovery: string, discovery_meta: string, pages: string, pages_meta: string, failed: string, media: string, media_meta: string, media_extra: string, maintenance: list<string>, started_at: string, finished_at: string, duration: string}>
     */
    private function recentImportRuns(): array
    {
        return SeasonvarImportRun::query()
            ->latest('id')
            ->limit(10)
            ->get([
                'id',
                'mode',
                'status',
                'force',
                'forever',
                'cycles',
                'discovered',
                'stored',
                'selected',
                'parsed',
                'failed',
                'media_attached',
                'media_updated',
                'media_skipped',
                'media_failed',
                'summary',
                'started_at',
                'finished_at',
                'updated_at',
            ])
            ->map(fn (SeasonvarImportRun $run): array => $this->importRunRow($run))
            ->all();
    }

    /**
     * @return array{id: string, mode: string, status: string, status_class: string, status_tone: string, options: list<string>, cycles: string, discovery: string, discovery_meta: string, pages: string, pages_meta: string, failed: string, media: string, media_meta: string, media_extra: string, maintenance: list<string>, started_at: string, finished_at: string, duration: string}
     */
    private function importRunRow(SeasonvarImportRun $run): array
    {
        return [
            'id' => '#'.$run->id,
            'mode' => $this->displayValue('seasonvar_import_runs', 'mode', $run->mode),
            'status' => $this->displayValue('seasonvar_import_runs', 'status', $run->status),
            'status_class' => $this->runStatusClass((string) $run->status),
            'status_tone' => $this->runStatusTone((string) $run->status),
            'options' => $this->runOptionLabels($run),
            'cycles' => $this->formatStat((int) $run->cycles),
            'discovery' => $this->formatStat((int) $run->discovered).' / '.$this->formatStat((int) $run->stored),
            'discovery_meta' => 'найдено / сохранено',
            'pages' => $this->formatStat((int) $run->selected).' / '.$this->formatStat((int) $run->parsed),
            'pages_meta' => 'выбрано / обновлено',
            'failed' => $this->formatStat((int) $run->failed),
            'media' => $this->formatStat((int) $run->media_attached).' / '.$this->formatStat((int) $run->media_updated),
            'media_meta' => 'добавлено / обновлено',
            'media_extra' => 'пропущено: '.$this->formatStat((int) $run->media_skipped).', ошибок: '.$this->formatStat((int) $run->media_failed),
            'maintenance' => $this->runMaintenanceRows($run->summary),
            'started_at' => $this->dateValue($run->started_at),
            'finished_at' => $this->dateValue($run->finished_at),
            'duration' => $this->durationValue($run->started_at, $run->finished_at),
        ];
    }

    private function runStatusClass(string $status): string
    {
        return match ($status) {
            'completed' => 'text-emerald-700',
            'failed' => 'text-rose-700',
            'running', 'partial' => 'text-amber-700',
            'queued' => 'text-sky-700',
            default => 'text-slate-700',
        };
    }

    private function runStatusTone(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'failed' => 'danger',
            'running', 'partial' => 'warning',
            'queued' => 'sky',
            default => 'muted',
        };
    }

    /**
     * @return list<string>
     */
    private function runOptionLabels(SeasonvarImportRun $run): array
    {
        $options = [];

        if ((bool) $run->force) {
            $options[] = 'Принудительно';
        }

        if ((bool) $run->forever) {
            $options[] = 'Без остановки';
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function runMaintenanceRows(mixed $summary): array
    {
        if (! is_array($summary)) {
            return ['нет дополнительных действий'];
        }

        $rows = [];
        $sourceBackfill = $this->summarySection($summary, 'last_source_status_backfill');
        $sourceBackfillSelected = $this->summaryInt($sourceBackfill, 'selected');
        $sourceBackfilled = $this->summaryInt($sourceBackfill, 'backfilled');

        if ($sourceBackfillSelected > 0 || $sourceBackfilled > 0) {
            $rows[] = 'Статусы страниц: '.$this->formatStat($sourceBackfilled).' / '.$this->formatStat($sourceBackfillSelected);
        }

        $mediaMetadata = $this->summarySection($summary, 'last_media_metadata_backlog');
        $mediaMetadataChecked = $this->summaryInt($mediaMetadata, 'media_checked');
        $mediaMetadataUpdated = $this->summaryInt($mediaMetadata, 'media_updated');

        if ($mediaMetadataChecked > 0 || $mediaMetadataUpdated > 0) {
            $rows[] = 'Данные видео: '.$this->formatStat($mediaMetadataUpdated).' / '.$this->formatStat($mediaMetadataChecked);
        }

        $mediaSourceKeys = $this->summarySection($summary, 'last_media_source_key_backlog');
        $mediaSourceKeysChecked = $this->summaryInt($mediaSourceKeys, 'media_checked');
        $mediaSourceKeysUpdated = $this->summaryInt($mediaSourceKeys, 'media_updated');

        if ($mediaSourceKeysChecked > 0 || $mediaSourceKeysUpdated > 0) {
            $rows[] = 'Ключи видео: '.$this->formatStat($mediaSourceKeysUpdated).' / '.$this->formatStat($mediaSourceKeysChecked);
        }

        $mediaBacklog = $this->summarySection($summary, 'last_media_backlog');
        $mediaChecked = $this->summaryInt($mediaBacklog, 'media_checked');
        $mediaAvailable = $this->summaryInt($mediaBacklog, 'media_available');
        $mediaUnavailable = $this->summaryInt($mediaBacklog, 'media_unavailable');

        if ($mediaChecked > 0 || $mediaAvailable > 0 || $mediaUnavailable > 0) {
            $rows[] = 'Проверка видео: '.$this->formatStat($mediaChecked).', доступно '.$this->formatStat($mediaAvailable).', недоступно '.$this->formatStat($mediaUnavailable);
        }

        $relationCleanup = $this->summarySection($summary, 'last_relation_cleanup');
        $relationRecordsRemoved = $this->summaryInt($relationCleanup, 'records_removed') + $this->summaryInt($relationCleanup, 'legacy_records_removed');
        $relationLinksRemoved = $this->summaryInt($relationCleanup, 'links_removed') + $this->summaryInt($relationCleanup, 'legacy_links_removed');

        if ($relationRecordsRemoved > 0 || $relationLinksRemoved > 0) {
            $rows[] = 'Справочники: удалено записей '.$this->formatStat($relationRecordsRemoved).', связей '.$this->formatStat($relationLinksRemoved);
        }

        $merge = $this->summarySection($summary, 'last_merge');
        $mergedTitles = $this->summaryInt($merge, 'titles');
        $mergedSeasons = $this->summaryInt($merge, 'seasons');
        $mergedEpisodes = $this->summaryInt($merge, 'episodes');

        if ($mergedTitles > 0 || $mergedSeasons > 0 || $mergedEpisodes > 0) {
            $rows[] = 'Объединение: '.$this->formatStat($mergedTitles).' / '.$this->formatStat($mergedSeasons).' / '.$this->formatStat($mergedEpisodes);
        }

        $discovery = $this->summarySection($summary, 'last_discovery');
        $cleaned = $this->summaryInt($discovery, 'cleaned');

        if ($cleaned > 0) {
            $rows[] = 'Некорректные ссылки: '.$this->formatStat($cleaned);
        }

        return $rows === [] ? ['нет дополнительных действий'] : $rows;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function summarySection(array $summary, string $key): array
    {
        $section = $summary[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function summaryInt(array $summary, string $key): int
    {
        return max(0, (int) ($summary[$key] ?? 0));
    }

    /**
     * @param  array<string, int>  $generatedCounts
     * @return list<array{name: string, uri: string, label: string, address: string, kind: string, scope: string, generated_count: int, generated_display: string}>
     */
    private function routeRows(array $generatedCounts): array
    {
        return $this->namedGetRoutes()
            ->map(function (RouteDefinition $route) use ($generatedCounts): array {
                $name = (string) $route->getName();
                $uri = $route->uri();

                return [
                    'name' => $name,
                    'uri' => $uri === '/' ? '/' : '/'.ltrim($uri, '/'),
                    'label' => $this->routeLabel($name),
                    'address' => $this->routeAddressLabel($name),
                    'kind' => $this->routeKind($name),
                    'scope' => str_contains($uri, '{') ? 'Параметризованный' : 'Статический',
                    'generated_count' => $generatedCounts[$name] ?? 0,
                    'generated_display' => $this->formatStat($generatedCounts[$name] ?? 0),
                ];
            })
            ->sortBy([['kind', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, RouteDefinition>
     */
    private function namedGetRoutes(): Collection
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route): bool => $route->getName() !== null && in_array('GET', $route->methods(), true))
            ->values();
    }

    private function routeKind(string $routeName): string
    {
        return match (true) {
            str_starts_with($routeName, 'sitemap') => 'Карта сайта',
            in_array($routeName, ['feed', 'opensearch', 'llms'], true) => 'Служебные файлы',
            str_starts_with($routeName, 'titles.') => 'Каталог',
            str_starts_with($routeName, 'stats') => 'Служебный раздел',
            default => 'Основные страницы',
        };
    }

    private function routeLabel(string $routeName): string
    {
        return match ($routeName) {
            'home' => 'Главная',
            'stats' => 'Сводка каталога',
            'stats.poster' => 'Постер статистики',
            'titles.index' => 'Каталог',
            'titles.year' => 'Страница года',
            'titles.taxonomy' => 'Раздел справочника',
            'titles.show' => 'Страница сериала',
            'sitemap' => 'Карта сайта',
            'sitemap.index' => 'Индекс карты сайта',
            'sitemap.static' => 'Основные страницы карты',
            'sitemap.taxonomies' => 'Справочники карты',
            'sitemap.landings' => 'Страницы справочников по годам',
            'sitemap.titles' => 'Сериалы карты',
            'sitemap.videos' => 'Видео карты',
            'feed' => 'Лента обновлений',
            'opensearch' => 'Поиск браузера',
            'llms' => 'Текстовый справочник',
            default => 'Раздел портала',
        };
    }

    private function routeAddressLabel(string $routeName): string
    {
        return match ($routeName) {
            'home' => 'Главная страница',
            'stats' => 'Сводка каталога',
            'stats.poster' => 'Внутреннее изображение сериала',
            'titles.index' => 'Список сериалов',
            'titles.year' => 'Сериалы выбранного года',
            'titles.taxonomy' => 'Сериалы выбранного справочника',
            'titles.show' => 'Внутренняя страница сериала',
            'sitemap' => 'Карта сайта',
            'sitemap.index' => 'Индекс карты сайта',
            'sitemap.static' => 'Основные страницы карты сайта',
            'sitemap.taxonomies' => 'Справочники карты сайта',
            'sitemap.landings' => 'Страницы справочников по годам',
            'sitemap.titles' => 'Файлы карты с сериалами',
            'sitemap.videos' => 'Файлы карты с видео',
            'feed' => 'Лента последних обновлений',
            'opensearch' => 'Подключение поиска в браузере',
            'llms' => 'Текстовый справочник для чтения',
            default => 'Служебная страница',
        };
    }

    /**
     * @return Collection<int, array{label: string, place: string, route: string, count: int, count_display: string, meta: string}>
     */
    private function internalLinkRows(
        int $publishedTitleUrls,
        int $yearUrls,
        int $taxonomyUrls,
        int $landingUrls,
        int $videoPlayerLinks,
        int $episodePlayerLinks,
        int $sitemapDocuments,
    ): Collection {
        return collect([
            $this->linkRow('Главная страница', 'Навигация и карта сайта', 'Главная', 1, 'основная страница'),
            $this->linkRow('Каталог', 'Навигация, поиск, карта сайта', 'Каталог', 1, 'страница с поиском'),
            $this->linkRow('Страницы годов', 'Каталог и карта сайта', 'Годы', $yearUrls, 'по опубликованным годам'),
            $this->linkRow('Страницы справочников', 'Фильтры и карта сайта', 'Справочники', $taxonomyUrls, 'жанры, страны, актеры, переводы и другие справочники'),
            $this->linkRow('Справочники по годам', 'Карта сайта и фильтры', 'Справочник с годом', $landingUrls, 'реальные пары справочника и года'),
            $this->linkRow('Публичные сериалы', 'Сериалы, списки, карта сайта', 'Сериалы', $publishedTitleUrls, 'только опубликованные сериалы'),
            $this->linkRow('Выбор серии на странице сериала', 'Плеер', 'Выбор серии', $episodePlayerLinks, 'серии с готовым видео'),
            $this->linkRow('Выбор видео на странице сериала', 'Плеер и карта видео', 'Выбор видео', $videoPlayerLinks, 'готовые внешние видео'),
            $this->linkRow('Файлы карты сайта', 'Поиск и индексация', 'Карта сайта', $sitemapDocuments, 'основные страницы, справочники, сериалы, видео'),
            $this->linkRow('Служебные файлы', 'Поиск и чтение', 'Ленты и справочники', 3, 'для обновлений и поиска'),
            $this->linkRow('Страница внутренней статистики', 'Служебная навигация', 'stats', 1, 'закрыто от индексации'),
        ]);
    }

    /**
     * @return array{label: string, place: string, route: string, count: int, count_display: string, meta: string}
     */
    private function linkRow(string $label, string $place, string $route, int $count, string $meta): array
    {
        return [
            'label' => $label,
            'place' => $place,
            'route' => $route,
            'count' => $count,
            'count_display' => $this->formatStat($count),
            'meta' => $meta,
        ];
    }

    private function publishedTitleUrlCount(): int
    {
        return $this->titles->visibleTo(null)
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->count();
    }

    private function publishedYearUrlCount(): int
    {
        return (int) $this->titles->visibleTo(null)
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->distinct()
            ->count('year');
    }

    private function publishedTaxonomyUrlCount(): int
    {
        return collect(self::FILTER_LINKS)
            ->sum(fn (array $config): int => (int) DB::table($config['pivot'])
                ->joinSub($this->titles->visibleTo(null)->select('catalog_titles.id'), 'visible_catalog_titles', 'visible_catalog_titles.id', '=', $config['pivot'].'.catalog_title_id')
                ->distinct()
                ->count($config['pivot'].'.'.$config['related_key']));
    }

    private function landingUrlCount(): int
    {
        return collect(self::LANDING_FILTER_TYPES)
            ->sum(function (string $filterType): int {
                $config = self::FILTER_LINKS[$filterType];
                $query = DB::table($config['pivot'])
                    ->joinSub($this->titles->visibleTo(null)->select(['catalog_titles.id', 'catalog_titles.year']), 'visible_catalog_titles', 'visible_catalog_titles.id', '=', $config['pivot'].'.catalog_title_id')
                    ->whereNotNull('visible_catalog_titles.year')
                    ->where('visible_catalog_titles.year', '>=', 1900)
                    ->where('visible_catalog_titles.year', '<=', (int) now()->format('Y') + 1)
                    ->select([
                        $config['pivot'].'.'.$config['related_key'].' as taxonomy_id',
                        'visible_catalog_titles.year as year',
                    ])
                    ->groupBy($config['pivot'].'.'.$config['related_key'], 'visible_catalog_titles.year');

                return (int) DB::query()->fromSub($query, 'landing_links')->count();
            });
    }

    private function publishedVideoUrlCount(): int
    {
        return LicensedMedia::query()
            ->availableTo(null)
            ->forAvailableReleases(null)
            ->whereIn('catalog_title_id', $this->titles->visibleTo(null)->select('id'))
            ->where(function (EloquentBuilder $query): void {
                $query->where('licensed_media.playback_url', 'like', 'https://%')
                    ->orWhere('licensed_media.playback_url', 'like', 'http://%')
                    ->orWhere('licensed_media.path', 'like', 'https://%')
                    ->orWhere('licensed_media.path', 'like', 'http://%');
            })
            ->count();
    }

    private function episodePlayerLinkCount(): int
    {
        return LicensedMedia::query()
            ->availableTo(null)
            ->forAvailableReleases(null)
            ->whereIn('catalog_title_id', $this->titles->visibleTo(null)->select('id'))
            ->whereNotNull('licensed_media.episode_id')
            ->distinct()
            ->count('licensed_media.episode_id');
    }

    private function paginatedPageCount(int $items, int $pageSize): int
    {
        return max(1, (int) ceil($items / $pageSize));
    }

    /**
     * @return Collection<int, array{label: string, field: string, total_display: string, filled_display: string, unique_display: string, absolute_display: string, empty_display: string, coverage: string}>
     */
    private function externalUrlFieldRows(): Collection
    {
        return collect($this->externalUrlFields())
            ->map(function (array $field): array {
                $total = $this->tableCount($field['table']);
                $filled = $this->presentCount($field['table'], $field['column']);
                $empty = max(0, $total - $filled);

                return [
                    'label' => $field['label'],
                    'field' => $this->tableLabel($field['table']).' - '.$this->columnLabel($field['column']),
                    'total_display' => $this->formatStat($total),
                    'filled_display' => $this->formatStat($filled),
                    'unique_display' => $this->formatStat($this->distinctPresentCount($field['table'], $field['column'])),
                    'absolute_display' => $this->formatStat($this->absoluteUrlCount($field['table'], $field['column'])),
                    'empty_display' => $this->formatStat($empty),
                    'coverage' => $this->percent($filled, $total),
                ];
            })
            ->sortBy('label')
            ->values();
    }

    /**
     * @param  Collection<int, array{label: string, table: string, total: int, total_display: string, group: string}>  $databaseTables
     * @return array{
     *     totals: array{indexes: int, expected_indexes: int, missing_expected_indexes: int},
     *     summary_sections: list<array{title: string, icon: string, rows: list<array{label: string, value: mixed, display: string, meta: string|null}>}>,
     *     expected_index_rows: Collection<int, array{label: string, table: string, columns: string, present: bool, status: string}>,
     *     index_rows: Collection<int, array{label: string, records_display: string, indexes_display: string, secondary_display: string, unique_display: string, coverage: string}>,
     *     issue_rows: Collection<int, array{label: string, table: string, columns: string, present: false, status: string}>
     * }
     */
    private function databaseOptimizationStats(Collection $databaseTables): array
    {
        $rawIndexRows = $this->databaseIndexRows();
        $indexRows = $this->databaseIndexSummaryRows($databaseTables, $rawIndexRows);
        $expectedIndexRows = $this->expectedIndexRows();
        $missingRows = $expectedIndexRows
            ->filter(fn (array $row): bool => $row['present'] === false)
            ->values();
        $indexedTables = $rawIndexRows->pluck('table')->unique()->count();

        return [
            'totals' => [
                'indexes' => $rawIndexRows->count(),
                'expected_indexes' => $expectedIndexRows->count(),
                'missing_expected_indexes' => $missingRows->count(),
            ],
            'summary_sections' => [
                [
                    'title' => 'Индексы базы',
                    'icon' => 'fa-solid fa-gauge-high',
                    'rows' => [
                        $this->row('Индексов всего', $rawIndexRows->count()),
                        $this->row('Разделов с индексами', $indexedTables, $this->percent($indexedTables, $databaseTables->count())),
                        $this->row('Проверено важных индексов', $expectedIndexRows->count()),
                        $this->row('Не хватает важных индексов', $missingRows->count()),
                    ],
                ],
            ],
            'expected_index_rows' => $expectedIndexRows,
            'index_rows' => $indexRows,
            'issue_rows' => $missingRows,
        ];
    }

    /**
     * @param  Collection<int, array{label: string, table: string, total: int, total_display: string, group: string}>  $databaseTables
     * @param  Collection<int, array{table: string, name: string, unique: bool, origin: string, partial: bool, columns: string}>  $rawIndexRows
     * @return Collection<int, array{label: string, records_display: string, indexes_display: string, secondary_display: string, unique_display: string, coverage: string}>
     */
    private function databaseIndexSummaryRows(Collection $databaseTables, Collection $rawIndexRows): Collection
    {
        $indexesByTable = $rawIndexRows->groupBy('table');

        return $databaseTables
            ->map(function (array $table) use ($indexesByTable): array {
                $indexes = $indexesByTable->get($table['table'], collect());
                $fieldSets = $indexes
                    ->pluck('columns')
                    ->filter()
                    ->unique()
                    ->count();

                return [
                    'label' => $table['label'],
                    'records_display' => $table['total_display'],
                    'indexes_display' => $this->formatStat($indexes->count()),
                    'secondary_display' => $this->formatStat($indexes->where('origin', 'c')->count()),
                    'unique_display' => $this->formatStat($indexes->where('unique', true)->count()),
                    'coverage' => $fieldSets === 0 ? 'нет индексов' : 'наборов полей: '.$this->formatStat($fieldSets),
                ];
            })
            ->sortBy('label')
            ->values();
    }

    /**
     * @return Collection<int, array{label: string, table: string, columns: string, present: bool, status: string}>
     */
    private function expectedIndexRows(): Collection
    {
        return collect($this->expectedIndexChecks())
            ->map(function (array $check): array {
                $present = $this->hasIndexColumns($check['table'], $check['columns']);

                return [
                    'label' => $check['label'],
                    'table' => $this->tableLabel($check['table']),
                    'columns' => $this->columnListLabel($check['columns']),
                    'present' => $present,
                    'status' => $present ? 'Готово' : 'Нужно добавить',
                ];
            })
            ->values();
    }

    /**
     * @return list<array{label: string, table: string, columns: list<string>}>
     */
    private function expectedIndexChecks(): array
    {
        return [
            ['label' => 'Сериалы по источнику', 'table' => 'catalog_titles', 'columns' => ['source_id', 'source_url_hash']],
            ['label' => 'Сериалы по году и обновлению', 'table' => 'catalog_titles', 'columns' => ['year', 'indexed_at']],
            ['label' => 'Лента по опубликованным сериалам', 'table' => 'catalog_titles', 'columns' => ['is_published', 'indexed_at', 'id']],
            ['label' => 'Жанры в фильтрах', 'table' => 'catalog_title_genre', 'columns' => ['genre_id', 'catalog_title_id']],
            ['label' => 'Страны в фильтрах', 'table' => 'catalog_title_country', 'columns' => ['country_id', 'catalog_title_id']],
            ['label' => 'Актеры в фильтрах', 'table' => 'catalog_title_actor', 'columns' => ['actor_id', 'catalog_title_id']],
            ['label' => 'Режиссеры в фильтрах', 'table' => 'catalog_title_director', 'columns' => ['director_id', 'catalog_title_id']],
            ['label' => 'Переводы в фильтрах', 'table' => 'catalog_title_translation', 'columns' => ['translation_id', 'catalog_title_id']],
            ['label' => 'Возрастные рейтинги в фильтрах', 'table' => 'age_rating_catalog_title', 'columns' => ['age_rating_id', 'catalog_title_id']],
            ['label' => 'Страницы для обновления', 'table' => 'source_pages', 'columns' => ['parse_status', 'page_type', 'id']],
            ['label' => 'Свежесть страниц источника', 'table' => 'source_pages', 'columns' => ['page_type', 'parse_status', 'last_crawled_at', 'id']],
            ['label' => 'Видео сериала по готовности', 'table' => 'licensed_media', 'columns' => ['catalog_title_id', 'status', 'published_at']],
            ['label' => 'Лента новых видео', 'table' => 'licensed_media', 'columns' => ['status', 'published_at', 'id']],
            ['label' => 'Видео серии по качеству', 'table' => 'licensed_media', 'columns' => ['episode_id', 'status', 'quality']],
            ['label' => 'Постоянный ключ видео', 'table' => 'licensed_media', 'columns' => ['catalog_title_id', 'source_media_key']],
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexColumns(string $table, array $columns): bool
    {
        $columnSignature = implode(', ', $columns);

        return $this->databaseIndexRows()
            ->contains(fn (array $index): bool => $index['table'] === $table && $index['columns'] === $columnSignature);
    }

    /**
     * @return Collection<int, array{table: string, name: string, unique: bool, origin: string, partial: bool, columns: string}>
     */
    private function databaseIndexRows(): Collection
    {
        if ($this->databaseIndexes !== null) {
            return $this->databaseIndexes;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return $this->databaseIndexes = collect();
        }

        try {
            $rows = $this->tableNames()
                ->flatMap(function (string $table): Collection {
                    return collect(DB::select("PRAGMA index_list('".$this->sqliteLiteral($table)."')"))
                        ->map(function (object $index) use ($table): array {
                            $name = (string) $index->name;

                            return [
                                'table' => $table,
                                'name' => $name,
                                'unique' => (bool) $index->unique,
                                'origin' => (string) $index->origin,
                                'partial' => (bool) $index->partial,
                                'columns' => $this->sqliteIndexColumns($name),
                            ];
                        });
                })
                ->sortBy([['table', 'asc'], ['name', 'asc']])
                ->values();

            return $this->databaseIndexes = $rows;
        } catch (Throwable) {
            return $this->databaseIndexes = collect();
        }
    }

    private function sqliteIndexColumns(string $indexName): string
    {
        return collect(DB::select("PRAGMA index_info('".$this->sqliteLiteral($indexName)."')"))
            ->sortBy('seqno')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->implode(', ');
    }

    private function sqliteLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * @param  list<string>  $columns
     */
    private function columnListLabel(array $columns): string
    {
        return collect($columns)
            ->map(fn (string $column): string => $this->columnLabel($column))
            ->implode(', ');
    }

    private function columnLabel(string $column): string
    {
        return match ($column) {
            'source_id' => 'источник',
            'source_url_hash' => 'адрес источника',
            'is_published' => 'публикация',
            'slug' => 'адрес сериала',
            'created_at' => 'дата создания',
            'year' => 'год',
            'indexed_at' => 'дата добавления',
            'genre_id' => 'жанр',
            'country_id' => 'страна',
            'actor_id' => 'актер',
            'director_id' => 'режиссер',
            'translation_id' => 'перевод',
            'age_rating_id' => 'возрастной рейтинг',
            'catalog_title_id' => 'сериал',
            'parse_status' => 'состояние сбора',
            'page_type' => 'тип страницы',
            'id' => 'номер',
            'last_crawled_at' => 'дата проверки',
            'status' => 'состояние',
            'level' => 'важность',
            'published_at' => 'дата публикации',
            'episode_id' => 'серия',
            'quality' => 'качество',
            'source_media_key' => 'ключ видео',
            'source_url' => 'адрес источника',
            'poster_url' => 'постер',
            'path' => 'ссылка на файл',
            'playback_url' => 'ссылка воспроизведения',
            'url' => 'адрес страницы',
            'discovered_from_url' => 'адрес обнаружения',
            default => $column,
        };
    }

    /**
     * @return list<array{title: string, icon: string, rows: list<array{label: string, value: mixed, total: int, total_display: string, meta: string|null}>}>
     */
    private function groupSections(): array
    {
        return [
            ['title' => 'Сериалы по типам', 'icon' => 'fa-solid fa-shapes', 'rows' => $this->groupedCounts('catalog_titles', 'type')],
            ['title' => 'Сериалы по годам', 'icon' => 'fa-solid fa-calendar-days', 'rows' => $this->groupedCounts('catalog_titles', 'year')],
            ['title' => 'Страницы источника по типам', 'icon' => 'fa-solid fa-file-lines', 'rows' => $this->groupedCounts('source_pages', 'page_type')],
            ['title' => 'Страницы источника по состоянию сбора', 'icon' => 'fa-solid fa-code-branch', 'rows' => $this->groupedCounts('source_pages', 'parse_status')],
            ['title' => 'Страницы источника по состоянию обновления', 'icon' => 'fa-solid fa-rotate', 'rows' => $this->groupedCounts('source_pages', 'import_status')],
            ['title' => 'Ответы источника', 'icon' => 'fa-solid fa-server', 'rows' => $this->groupedCounts('source_pages', 'http_status')],
            ['title' => 'Видео по готовности', 'icon' => 'fa-solid fa-signal', 'rows' => $this->groupedCounts('licensed_media', 'status')],
            ['title' => 'Видео по проверке', 'icon' => 'fa-solid fa-shield-halved', 'rows' => $this->groupedCounts('licensed_media', 'check_status')],
            ['title' => 'Видео по здоровью', 'icon' => 'fa-solid fa-heart-pulse', 'rows' => $this->groupedCounts('licensed_media', 'health_status')],
            ['title' => 'Видео по месту хранения', 'icon' => 'fa-solid fa-hard-drive', 'rows' => $this->groupedCounts('licensed_media', 'storage_disk')],
            ['title' => 'Видео по форматам', 'icon' => 'fa-solid fa-file-video', 'rows' => $this->groupedCounts('licensed_media', 'format')],
            ['title' => 'Видео по качеству', 'icon' => 'fa-solid fa-gauge-high', 'rows' => $this->groupedCounts('licensed_media', 'quality')],
            ['title' => 'Видео по переводу', 'icon' => 'fa-solid fa-language', 'rows' => $this->groupedCounts('licensed_media', 'translation_name')],
            ['title' => 'Запуски обновления по состоянию', 'icon' => 'fa-solid fa-diagram-project', 'rows' => $this->groupedCounts('seasonvar_import_runs', 'status')],
            ['title' => 'Запуски обновления по режимам', 'icon' => 'fa-solid fa-terminal', 'rows' => $this->groupedCounts('seasonvar_import_runs', 'mode')],
            ['title' => 'События обновления по важности', 'icon' => 'fa-solid fa-bell', 'rows' => $this->groupedCounts('seasonvar_import_events', 'level')],
            ['title' => 'События обновления по типам', 'icon' => 'fa-solid fa-list-check', 'rows' => $this->groupedCounts('seasonvar_import_events', 'event')],
            ['title' => 'Оценки по провайдерам', 'icon' => 'fa-solid fa-star', 'rows' => $this->ratingProviderRows()],
            ['title' => 'Алиасы по типам', 'icon' => 'fa-solid fa-tags', 'rows' => $this->groupedCounts('catalog_title_aliases', 'type')],
            ['title' => 'Алиасы по источникам', 'icon' => 'fa-solid fa-fingerprint', 'rows' => $this->groupedCounts('catalog_title_aliases', 'source')],
            ['title' => 'Недостающие данные', 'icon' => 'fa-solid fa-triangle-exclamation', 'rows' => $this->missingDataFlagRows()],
        ];
    }

    /**
     * @return list<array{label: string, value: mixed, display: string, meta: string|null}>
     */
    private function sourceAndImportRows(int $sourcePages): array
    {
        $runs = $this->tableCount('seasonvar_import_runs');
        $latestRun = SeasonvarImportRun::query()
            ->latest('id')
            ->first(['id', 'mode', 'status', 'cycles', 'selected', 'parsed', 'failed', 'media_attached', 'media_updated', 'started_at', 'finished_at']);

        $rows = [
            $this->row('Источников', $this->tableCount('sources')),
            $this->row('Активных источников', $this->whereCount('sources', fn (QueryBuilder $query): QueryBuilder => $query->where('is_active', true))),
            $this->row('Страниц источника', $sourcePages),
            $this->row('С контрольной суммой', $this->presentCount('source_pages', 'content_hash'), $this->percent($this->presentCount('source_pages', 'content_hash'), $sourcePages)),
            $this->row('Проверялись источником', $this->presentCount('source_pages', 'last_crawled_at'), $this->percent($this->presentCount('source_pages', 'last_crawled_at'), $sourcePages)),
            $this->row('Уже обновлялись', $this->presentCount('source_pages', 'last_imported_at'), $this->percent($this->presentCount('source_pages', 'last_imported_at'), $sourcePages)),
            $this->row('Ожидают повторной попытки', $this->whereCount('source_pages', fn (QueryBuilder $query): QueryBuilder => $query->whereNotNull('retry_after_at'))),
            $this->row('Запусков обновления', $runs),
            $this->row('Событий обновления', $this->tableCount('seasonvar_import_events')),
            $this->row('Сохраненных копий страниц', $this->tableCount('source_page_snapshots')),
        ];

        if ($latestRun !== null) {
            $rows[] = $this->row('Последний запуск', '#'.$latestRun->id, $this->displayValue('seasonvar_import_runs', 'mode', $latestRun->mode).' / '.$this->displayValue('seasonvar_import_runs', 'status', $latestRun->status));
            $rows[] = $this->row('Страниц выбрано в последнем запуске', (int) $latestRun->selected);
            $rows[] = $this->row('Страниц обновлено в последнем запуске', (int) $latestRun->parsed);
            $rows[] = $this->row('Ошибок в последнем запуске', (int) $latestRun->failed);
            $rows[] = $this->row('Видео добавлено/обновлено', ((int) $latestRun->media_attached).' / '.((int) $latestRun->media_updated));
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, value: mixed, display: string, meta: string|null}>
     */
    private function freshnessRows(): array
    {
        return [
            $this->row('Первое добавление сериала', $this->dateValue(DB::table('catalog_titles')->min('indexed_at'))),
            $this->row('Последнее добавление сериала', $this->dateValue(DB::table('catalog_titles')->max('indexed_at'))),
            $this->row('Последняя проверка источника', $this->dateValue(DB::table('source_pages')->max('last_crawled_at'))),
            $this->row('Последнее обновление источника', $this->dateValue(DB::table('source_pages')->max('last_imported_at'))),
            $this->row('Последнее изменение страницы источника', $this->dateValue(DB::table('source_pages')->max('last_changed_at'))),
            $this->row('Последняя проверка видео', $this->dateValue(DB::table('licensed_media')->max('checked_at'))),
            $this->row('Последняя публикация видео', $this->dateValue(DB::table('licensed_media')->max('published_at'))),
            $this->row('Последний старт обновления', $this->dateValue(DB::table('seasonvar_import_runs')->max('started_at'))),
            $this->row('Последнее завершение обновления', $this->dateValue(DB::table('seasonvar_import_runs')->max('finished_at'))),
            $this->row('Последняя сохраненная копия страницы', $this->dateValue(DB::table('source_page_snapshots')->max('captured_at'))),
        ];
    }

    /**
     * @return list<array{label: string, value: mixed, total: int, total_display: string, meta: string|null}>
     */
    private function ratingProviderRows(): array
    {
        return DB::table('catalog_title_ratings')
            ->selectRaw('provider as value, COUNT(*) as total, AVG(rating) as average_rating')
            ->groupBy('provider')
            ->orderByDesc('total')
            ->orderBy('provider')
            ->get()
            ->map(fn (object $row): array => $this->groupRow(
                $this->displayValue('catalog_title_ratings', 'provider', $row->value),
                $row->value,
                (int) $row->total,
                $row->average_rating === null ? null : 'средняя '.number_format((float) $row->average_rating, 2, '.', ' '),
            ))
            ->all();
    }

    /**
     * @return list<array{label: string, value: mixed, total: int, total_display: string, meta: string|null}>
     */
    private function missingDataFlagRows(): array
    {
        $flags = [];

        SourcePage::query()
            ->whereNotNull('missing_data_flags')
            ->select(['missing_data_flags'])
            ->cursor()
            ->each(function (SourcePage $page) use (&$flags): void {
                foreach (($page->missing_data_flags ?? []) as $flag) {
                    $key = (string) $flag;
                    $flags[$key] = ($flags[$key] ?? 0) + 1;
                }
            });

        ksort($flags);

        return collect($flags)
            ->map(fn (int $total, string $flag): array => $this->groupRow(
                $this->displayValue('source_pages', 'missing_data_flags', $flag),
                $flag,
                $total,
            ))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{label: string, table: string, total: int, total_display: string, group: string}>
     */
    private function databaseTables(): Collection
    {
        return $this->tableNames()
            ->map(function (string $table): array {
                $total = $this->tableCount($table);

                return [
                    'label' => $this->tableLabel($table),
                    'table' => $table,
                    'total' => $total,
                    'total_display' => $this->formatStat($total),
                    'group' => $this->tableGroup($table),
                ];
            })
            ->sortBy([['group', 'asc'], ['table', 'asc']])
            ->values();
    }

    /**
     * @return Collection<int, array{label: string, table: string, records: int, records_display: string, links: int, links_display: string, linked_titles: int, linked_titles_display: string}>
     */
    private function taxonomyRows(): Collection
    {
        return collect([
            ['label' => 'Жанры', 'table' => 'genres', 'pivot' => 'catalog_title_genre'],
            ['label' => 'Страны', 'table' => 'countries', 'pivot' => 'catalog_title_country'],
            ['label' => 'Актеры', 'table' => 'actors', 'pivot' => 'catalog_title_actor'],
            ['label' => 'Режиссеры', 'table' => 'directors', 'pivot' => 'catalog_title_director'],
            ['label' => 'Возрастные рейтинги', 'table' => 'age_ratings', 'pivot' => 'age_rating_catalog_title'],
            ['label' => 'Переводы', 'table' => 'translations', 'pivot' => 'catalog_title_translation'],
            ['label' => 'Статусы каталога', 'table' => 'catalog_statuses', 'pivot' => 'catalog_status_catalog_title'],
            ['label' => 'Каналы', 'table' => 'networks', 'pivot' => 'catalog_title_network'],
            ['label' => 'Студии', 'table' => 'studios', 'pivot' => 'catalog_title_studio'],
            ['label' => 'Теги', 'table' => 'tags', 'pivot' => 'catalog_title_tag'],
            ['label' => 'Старые taxonomies', 'table' => 'taxonomies', 'pivot' => 'catalog_title_taxonomy'],
        ])->map(function (array $row): array {
            $records = $this->tableCount($row['table']);
            $links = $this->tableCount($row['pivot']);
            $linkedTitles = DB::table($row['pivot'])->distinct()->count('catalog_title_id');

            return [
                'label' => $row['label'],
                'table' => $row['table'],
                'records' => $records,
                'records_display' => $this->formatStat($records),
                'links' => $links,
                'links_display' => $this->formatStat($links),
                'linked_titles' => $linkedTitles,
                'linked_titles_display' => $this->formatStat($linkedTitles),
            ];
        });
    }

    /**
     * @return list<array{label: string, value: mixed, total: int, total_display: string, meta: string|null}>
     */
    private function groupedCounts(string $table, string $column): array
    {
        return DB::table($table)
            ->selectRaw($column.' as value, COUNT(*) as total')
            ->groupBy($column)
            ->orderByDesc('total')
            ->orderBy($column)
            ->get()
            ->map(fn (object $row): array => $this->groupRow(
                $this->displayValue($table, $column, $row->value),
                $row->value,
                (int) $row->total,
            ))
            ->all();
    }

    /**
     * @return array{label: string, value: mixed, total: int, total_display: string, meta: string|null}
     */
    private function groupRow(string $label, mixed $value, int $total, ?string $meta = null): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'total' => $total,
            'total_display' => $this->formatStat($total),
            'meta' => $meta,
        ];
    }

    private function tableCount(string $table): int
    {
        return $this->tableCounts[$table] ??= (int) DB::table($table)->count();
    }

    private function whereCount(string $table, Closure $callback): int
    {
        return (int) $callback(DB::table($table))->count();
    }

    private function presentCount(string $table, string $column): int
    {
        $key = $table.'.'.$column;

        return $this->presentCounts[$key] ??= $this->whereCount($table, fn (QueryBuilder $query): QueryBuilder => $query
            ->whereNotNull($column)
            ->where($column, '!=', ''));
    }

    private function distinctPresentCount(string $table, string $column): int
    {
        $key = $table.'.'.$column;

        return $this->distinctPresentCounts[$key] ??= (int) DB::table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->count($column);
    }

    private function absoluteUrlCount(string $table, string $column): int
    {
        $key = $table.'.'.$column;

        return $this->absoluteUrlCounts[$key] ??= $this->whereCount($table, fn (QueryBuilder $query): QueryBuilder => $query
            ->where(function (QueryBuilder $query) use ($column): void {
                $query
                    ->where($column, 'like', 'https://%')
                    ->orWhere($column, 'like', 'http://%');
            }));
    }

    private function missingCount(string $table, string $column): int
    {
        return max(0, $this->tableCount($table) - $this->presentCount($table, $column));
    }

    private function titlesMissingPivotCount(string $pivotTable): int
    {
        return (int) DB::table('catalog_titles')
            ->leftJoin($pivotTable, 'catalog_titles.id', '=', $pivotTable.'.catalog_title_id')
            ->whereNull($pivotTable.'.catalog_title_id')
            ->count('catalog_titles.id');
    }

    private function staleSourcePagesCount(): int
    {
        return $this->whereCount('source_pages', fn (QueryBuilder $query): QueryBuilder => $query
            ->where(function (QueryBuilder $query): void {
                $query
                    ->whereNull('last_crawled_at')
                    ->orWhere('last_crawled_at', '<', now()->subDays(30));
            }));
    }

    private function sinceCount(string $table, string $dateColumn, ?Carbon $since, ?Closure $callback = null): int
    {
        $query = DB::table($table);

        if ($callback !== null) {
            $query = $callback($query);
        }

        if ($since !== null) {
            $query->where($dateColumn, '>=', $since);
        } else {
            $query->whereNotNull($dateColumn);
        }

        return (int) $query->count();
    }

    /**
     * @return array{label: string, value: int, total: int, display: string, meta: string, percent_value: float, severity: string, severity_label: string, row_class: string, value_class: string}
     */
    private function qualityRow(string $label, int $value, int $total, string $severity): array
    {
        $severityClasses = $this->severityClasses($severity);

        return [
            'label' => $label,
            'value' => $value,
            'total' => $total,
            'display' => $this->formatStat($value),
            'meta' => $this->percent($value, $total),
            'percent_value' => $this->percentValue($value, $total),
            'severity' => $severity,
            'severity_label' => $severityClasses['label'],
            'row_class' => $severityClasses['row_class'],
            'value_class' => $severityClasses['value_class'],
        ];
    }

    /**
     * @return array{label: string, row_class: string, value_class: string}
     */
    private function severityClasses(string $severity): array
    {
        return match ($severity) {
            'critical' => [
                'label' => 'Критично',
                'row_class' => 'bg-rose-50/70',
                'value_class' => 'text-rose-700',
            ],
            'warning' => [
                'label' => 'Проверить',
                'row_class' => 'bg-amber-50/70',
                'value_class' => 'text-amber-700',
            ],
            default => [
                'label' => 'Инфо',
                'row_class' => '',
                'value_class' => 'text-slate-800',
            ],
        };
    }

    /**
     * @return Collection<int, string>
     */
    private function tableNames(): Collection
    {
        try {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
                ->pluck('name')
                ->map(fn (mixed $name): string => (string) $name)
                ->values();
        } catch (Throwable) {
            return collect(array_keys($this->tableLabels()));
        }
    }

    /**
     * @return array<string, string>
     */
    private function tableLabels(): array
    {
        return [
            'catalog_titles' => 'Сериалы каталога',
            'seasons' => 'Сезоны',
            'episodes' => 'Серии',
            'licensed_media' => 'Видео',
            'sources' => 'Источники',
            'source_pages' => 'Страницы источника',
            'source_page_snapshots' => 'Сохраненные копии страниц',
            'seasonvar_import_runs' => 'Запуски обновления',
            'seasonvar_import_events' => 'События обновления',
            'genres' => 'Жанры',
            'countries' => 'Страны',
            'actors' => 'Актеры',
            'directors' => 'Режиссеры',
            'age_ratings' => 'Возрастные рейтинги',
            'translations' => 'Переводы',
            'catalog_statuses' => 'Статусы каталога',
            'networks' => 'Каналы',
            'studios' => 'Студии',
            'tags' => 'Теги',
            'taxonomies' => 'Архивные справочники',
            'catalog_title_aliases' => 'Алиасы названий',
            'catalog_title_ratings' => 'Оценки',
            'catalog_title_reviews' => 'Отзывы',
            'age_rating_catalog_title' => 'Связи возрастных рейтингов',
            'catalog_status_catalog_title' => 'Связи статусов каталога',
            'catalog_title_actor' => 'Связи актеров',
            'catalog_title_country' => 'Связи стран',
            'catalog_title_director' => 'Связи режиссеров',
            'catalog_title_genre' => 'Связи жанров',
            'catalog_title_network' => 'Связи каналов',
            'catalog_title_studio' => 'Связи студий',
            'catalog_title_tag' => 'Связи тегов',
            'catalog_title_taxonomy' => 'Архивные связи справочников',
            'catalog_title_translation' => 'Связи переводов',
            'migrations' => 'Миграции',
            'users' => 'Пользователи',
            'sessions' => 'Сессии',
            'jobs' => 'Очередь задач',
            'failed_jobs' => 'Ошибки задач',
            'job_batches' => 'Группы задач',
            'cache' => 'Кэш',
            'cache_locks' => 'Блокировки кэша',
            'password_reset_tokens' => 'Токены сброса пароля',
        ];
    }

    /**
     * @return list<array{table: string, column: string, label: string}>
     */
    private function externalUrlFields(): array
    {
        return [
            ['table' => 'catalog_titles', 'column' => 'source_url', 'label' => 'Источник сериала'],
            ['table' => 'catalog_titles', 'column' => 'poster_url', 'label' => 'Постер сериала'],
            ['table' => 'seasons', 'column' => 'source_url', 'label' => 'Источник сезона'],
            ['table' => 'episodes', 'column' => 'source_url', 'label' => 'Источник серии'],
            ['table' => 'licensed_media', 'column' => 'path', 'label' => 'Ссылка на видео'],
            ['table' => 'licensed_media', 'column' => 'playback_url', 'label' => 'Ссылка воспроизведения'],
            ['table' => 'licensed_media', 'column' => 'source_url', 'label' => 'Источник видео'],
            ['table' => 'source_pages', 'column' => 'url', 'label' => 'Страница источника'],
            ['table' => 'source_pages', 'column' => 'discovered_from_url', 'label' => 'Откуда найдена страница'],
            ['table' => 'source_page_snapshots', 'column' => 'url', 'label' => 'Ссылка сохраненной копии'],
            ['table' => 'genres', 'column' => 'source_url', 'label' => 'Источник жанра'],
            ['table' => 'countries', 'column' => 'source_url', 'label' => 'Источник страны'],
            ['table' => 'actors', 'column' => 'source_url', 'label' => 'Источник актера'],
            ['table' => 'directors', 'column' => 'source_url', 'label' => 'Источник режиссера'],
            ['table' => 'age_ratings', 'column' => 'source_url', 'label' => 'Источник возрастного рейтинга'],
            ['table' => 'translations', 'column' => 'source_url', 'label' => 'Источник перевода'],
            ['table' => 'catalog_statuses', 'column' => 'source_url', 'label' => 'Источник статуса'],
            ['table' => 'networks', 'column' => 'source_url', 'label' => 'Источник канала'],
            ['table' => 'studios', 'column' => 'source_url', 'label' => 'Источник студии'],
            ['table' => 'tags', 'column' => 'source_url', 'label' => 'Источник тега'],
            ['table' => 'taxonomies', 'column' => 'source_url', 'label' => 'Источник архивного справочника'],
        ];
    }

    private function tableLabel(string $table): string
    {
        return $this->tableLabels()[$table] ?? $table;
    }

    private function tableGroup(string $table): string
    {
        return match (true) {
            in_array($table, ['catalog_titles', 'seasons', 'episodes', 'licensed_media', 'catalog_title_aliases', 'catalog_title_ratings', 'catalog_title_reviews'], true) => 'Каталог',
            in_array($table, ['sources', 'source_pages', 'source_page_snapshots', 'seasonvar_import_runs', 'seasonvar_import_events'], true) => 'Обновление',
            str_contains($table, 'catalog_title_') || in_array($table, ['genres', 'countries', 'actors', 'directors', 'age_ratings', 'translations', 'catalog_statuses', 'networks', 'studios', 'tags', 'taxonomies'], true) => 'Справочники',
            default => 'Система',
        };
    }

    /**
     * @return array{label: string, value: mixed, display: string, meta: string|null}
     */
    private function row(string $label, mixed $value, ?string $meta = null): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'display' => $this->formatStat($value),
            'meta' => $meta,
        ];
    }

    private function formatStat(mixed $value): string
    {
        if (is_int($value)) {
            return number_format($value, 0, '.', ' ');
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', ' ');
        }

        if (is_numeric($value) && preg_match('/^-?\d+$/', (string) $value) === 1) {
            return number_format((int) $value, 0, '.', ' ');
        }

        return (string) $value;
    }

    private function average(int $value, int $total): string
    {
        if ($total === 0) {
            return '0';
        }

        return number_format($value / $total, 2, '.', ' ');
    }

    private function percent(int $value, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return number_format(($value / $total) * 100, 1, '.', ' ').'%';
    }

    private function percentValue(int $value, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(min(100, max(0, ($value / $total) * 100)), 1);
    }

    private function dateValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'нет данных';
        }

        try {
            return Carbon::parse($value)->format('d.m.Y H:i');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function durationValue(mixed $startedAt, mixed $finishedAt): string
    {
        if ($startedAt === null || $startedAt === '') {
            return 'нет данных';
        }

        try {
            $start = Carbon::parse($startedAt);
            $finish = $finishedAt === null || $finishedAt === '' ? now() : Carbon::parse($finishedAt);
        } catch (Throwable) {
            return 'нет данных';
        }

        return $this->humanDuration((int) $start->diffInSeconds($finish));
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return 'до минуты';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.' мин';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return trim($hours.' ч '.$remainingMinutes.' мин');
        }

        $days = intdiv($hours, 24);
        $remainingHours = $hours % 24;

        return trim($days.' д '.$remainingHours.' ч');
    }

    private function displayValue(string $table, string $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Не указано';
        }

        $key = $table.'.'.$column.'.'.$value;
        $labels = $this->valueLabels();

        if ($table === 'source_pages' && $column === 'page_type' && is_string($value)) {
            return Str::ucfirst(SeasonvarPageType::tryFrom($value)?->label() ?? (string) $value);
        }

        if (isset($labels[$key])) {
            return $labels[$key];
        }

        if ($value === 0 || $value === '0') {
            return '0';
        }

        if ($value === 1 || $value === '1') {
            return '1';
        }

        if (is_string($value) && preg_match('/seasonvar|сезонвар/iu', $value) === 1) {
            return $column === 'event' ? 'Событие обновления' : 'Основной источник';
        }

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    private function valueLabels(): array
    {
        return [
            'catalog_titles.type.serial' => 'Сериал',
            'catalog_titles.type.series' => 'Сериал',
            'catalog_titles.type.show' => 'Передача',
            'catalog_titles.type.tv_show' => 'Передача',
            'catalog_titles.type.anime' => 'Аниме',
            'catalog_titles.type.documentary' => 'Документальное',
            'catalog_titles.type.unknown' => 'Не определено',
            'source_pages.parse_status.pending' => 'Ожидает сбора',
            'source_pages.parse_status.parsed' => 'Собрано',
            'source_pages.parse_status.failed' => 'Ошибка',
            'source_pages.import_status.pending' => 'Ожидает обновления',
            'source_pages.import_status.parsed' => 'Обновлено',
            'source_pages.import_status.failed' => 'Ошибка',
            'source_pages.import_status.gone' => 'Страница недоступна',
            'source_pages.import_status.missing_data' => 'Данных пока не хватает',
            'source_pages.missing_data_flags.description' => 'Не хватает описания',
            'source_pages.missing_data_flags.poster' => 'Не хватает постера',
            'source_pages.missing_data_flags.poster_url' => 'Не хватает постера',
            'source_pages.missing_data_flags.episodes' => 'Не хватает серий',
            'source_pages.missing_data_flags.seasons_without_episodes' => 'Есть сезоны без серий',
            'source_pages.missing_data_flags.video' => 'Не хватает видео',
            'source_pages.missing_data_flags.media' => 'Не хватает видео',
            'source_pages.missing_data_flags.seasons_without_video' => 'Есть сезоны без видео',
            'source_pages.missing_data_flags.episodes_without_video' => 'Есть серии без видео',
            'source_pages.missing_data_flags.no_seasons' => 'Не хватает сезонов',
            'source_pages.missing_data_flags.no_episodes' => 'Не хватает серий',
            'source_pages.missing_data_flags.no_video' => 'Не хватает видео',
            'source_pages.missing_data_flags.no_published_video' => 'Нет опубликованного видео',
            'source_pages.missing_data_flags.unavailable_video' => 'Видео недоступно',
            'licensed_media.status.draft' => 'Готовится',
            'licensed_media.status.published' => 'Готово к просмотру',
            'licensed_media.status.unavailable' => 'Недоступно',
            'licensed_media.check_status.not_checked' => 'Ожидает проверки',
            'licensed_media.check_status.available' => 'Доступно',
            'licensed_media.check_status.unavailable' => 'Недоступно',
            'licensed_media.check_status.check_failed' => 'Проверка не удалась',
            'licensed_media.check_status.invalid_url' => 'Некорректная ссылка',
            'licensed_media.health_status.active' => 'Активно',
            'licensed_media.health_status.degraded' => 'Нестабильно',
            'licensed_media.health_status.unavailable' => 'Недоступно',
            'licensed_media.health_status.disabled' => 'Отключено',
            'licensed_media.storage_disk.external' => 'Внешний источник',
            'seasonvar_import_runs.status.running' => 'Выполняется',
            'seasonvar_import_runs.status.queued' => 'Ожидает запуска',
            'seasonvar_import_runs.status.completed' => 'Завершено',
            'seasonvar_import_runs.status.partial' => 'Завершено частично',
            'seasonvar_import_runs.status.failed' => 'Ошибка',
            'seasonvar_import_runs.status.cancelled' => 'Отменено',
            'seasonvar_import_runs.mode.sitemap' => 'Карта сайта',
            'seasonvar_import_runs.mode.url' => 'Одна страница',
            'seasonvar_import_runs.mode.pending' => 'Ожидающие страницы',
            'seasonvar_import_runs.mode.id' => 'По номеру',
            'seasonvar_import_runs.mode.all' => 'Полное обновление',
            'seasonvar_import_events.level.info' => 'Информация',
            'seasonvar_import_events.level.warning' => 'Внимание',
            'seasonvar_import_events.level.error' => 'Ошибка',
            'seasonvar_import_events.event.catalog-title-aliases-synced' => 'Дополнительные названия сохранены',
            'seasonvar_import_events.event.catalog-title-created' => 'Запись каталога создана',
            'seasonvar_import_events.event.catalog-title-ratings-synced' => 'Рейтинги сохранены',
            'seasonvar_import_events.event.catalog-title-reviews-synced' => 'Отзывы сохранены',
            'seasonvar_import_events.event.catalog-title-slug-prepared' => 'Адрес записи подготовлен',
            'seasonvar_import_events.event.catalog-title-updated' => 'Запись каталога обновлена',
            'seasonvar_import_events.event.catalog-title-upsert-started' => 'Сохранение записи каталога началось',
            'seasonvar_import_events.event.catalog-relations-cleanup-complete' => 'Очистка справочников завершена',
            'seasonvar_import_events.event.catalog-relations-cleanup-started' => 'Очистка справочников началась',
            'seasonvar_import_events.event.catalog-relations-cleanup-type-complete' => 'Тип справочника очищен',
            'seasonvar_import_events.event.catalog-relations-legacy-cleanup-complete' => 'Старые справочники очищены',
            'seasonvar_import_events.event.crawl-delay-not-needed' => 'Пауза обхода не нужна',
            'seasonvar_import_events.event.crawl-delay-skipped' => 'Пауза обхода пропущена',
            'seasonvar_import_events.event.crawl-delay-wait-complete' => 'Пауза обхода завершена',
            'seasonvar_import_events.event.crawl-delay-wait-started' => 'Пауза обхода началась',
            'seasonvar_import_events.event.episode-sync-complete' => 'Синхронизация серий завершена',
            'seasonvar_import_events.event.episode-sync-started' => 'Синхронизация серий началась',
            'seasonvar_import_events.event.html-parse-complete' => 'Обработка страницы завершена',
            'seasonvar_import_events.event.html-parse-started' => 'Обработка страницы началась',
            'seasonvar_import_events.event.http-request-complete' => 'Запрос завершен',
            'seasonvar_import_events.event.http-request-started' => 'Запрос начался',
            'seasonvar_import_events.event.page-parse-complete' => 'Страница обработана',
            'seasonvar_import_events.event.page-parse-failed' => 'Страница завершилась ошибкой',
            'seasonvar_import_events.event.page-parse-started' => 'Обработка страницы началась',
            'seasonvar_import_events.event.page-response-received' => 'Ответ страницы получен',
            'seasonvar_import_events.event.parse_failed' => 'Страница завершилась ошибкой',
            'seasonvar_import_events.event.parse-batch-complete' => 'Пакет обработки завершен',
            'seasonvar_import_events.event.parse-batch-item-complete' => 'Страница в пакете обработана',
            'seasonvar_import_events.event.parse-batch-item-failed' => 'Страница в пакете завершилась ошибкой',
            'seasonvar_import_events.event.parse-batch-item-started' => 'Обработка страницы в пакете началась',
            'seasonvar_import_events.event.parse-batch-started' => 'Пакет обработки начался',
            'seasonvar_import_events.event.season-sync-complete' => 'Синхронизация сезонов завершена',
            'seasonvar_import_events.event.season-sync-started' => 'Синхронизация сезонов началась',
            'seasonvar_import_events.event.seasonvar-import-complete' => 'Обновление каталога завершено',
            'seasonvar_import_events.event.seasonvar-import-cycle-complete' => 'Цикл обновления завершен',
            'seasonvar_import_events.event.seasonvar-import-cycle-started' => 'Цикл обновления начался',
            'seasonvar_import_events.event.seasonvar-import-failed' => 'Обновление каталога завершилось ошибкой',
            'seasonvar_import_events.event.seasonvar-import-season-url-failed' => 'Страница сезона завершилась ошибкой',
            'seasonvar_import_events.event.seasonvar-import-season-urls-selected' => 'Страницы сезонов выбраны',
            'seasonvar_import_events.event.seasonvar-import-started' => 'Обновление каталога запущено',
            'seasonvar_import_events.event.seasonvar-import-url-failed' => 'Страница по ссылке завершилась ошибкой',
            'seasonvar_import_events.event.seasonvar-media-attached' => 'Видео из страницы подключено',
            'seasonvar_import_events.event.seasonvar-media-backlog-complete' => 'Допроверка старых видео завершена',
            'seasonvar_import_events.event.seasonvar-media-backlog-started' => 'Допроверка старых видео началась',
            'seasonvar_import_events.event.seasonvar-media-metadata-backlog-complete' => 'Дополнение данных видео завершено',
            'seasonvar_import_events.event.seasonvar-media-metadata-backlog-started' => 'Дополнение данных видео началось',
            'seasonvar_import_events.event.seasonvar-media-metadata-updated' => 'Данные видео дополнены',
            'seasonvar_import_events.event.seasonvar-media-playlist-import-complete' => 'Плейлист из страницы обработан',
            'seasonvar_import_events.event.seasonvar-media-playlist-import-failed' => 'Плейлист из страницы завершился ошибкой',
            'seasonvar_import_events.event.seasonvar-media-skipped' => 'Видео из страницы пропущено',
            'seasonvar_import_events.event.seasonvar-media-source-key-backlog-complete' => 'Ключи старых видео дополнены',
            'seasonvar_import_events.event.seasonvar-media-source-key-backlog-started' => 'Дополнение ключей старых видео началось',
            'seasonvar_import_events.event.seasonvar-media-source-key-updated' => 'Ключ видео дополнен',
            'seasonvar_import_events.event.seasonvar-media-sync-complete' => 'Сохранение видео из страницы завершено',
            'seasonvar_import_events.event.seasonvar-media-sync-started' => 'Сохранение видео из страницы началось',
            'seasonvar_import_events.event.seasonvar-media-updated' => 'Видео из страницы обновлено',
            'seasonvar_import_events.event.seasonvar-media-url-checked' => 'Видео-ссылка проверена',
            'seasonvar_import_events.event.seasonvar-media-url-check-failed' => 'Проверка видео-ссылки завершилась ошибкой',
            'seasonvar_import_events.event.seasonvar-refresh-candidates-selected' => 'Страницы для обновления выбраны',
            'seasonvar_import_events.event.seasonvar-title-merge-complete' => 'Объединение сериалов завершено',
            'seasonvar_import_events.event.seasonvar-title-merged' => 'Страницы сезонов объединены',
            'seasonvar_import_events.event.sitemap-fetch-failed' => 'Загрузка карты сайта завершилась ошибкой',
            'seasonvar_import_events.event.sitemap-mirror-archive-ready' => 'Архив карты сайта готов',
            'seasonvar_import_events.event.sitemap-mirror-complete' => 'Зеркало карты сайта готово',
            'seasonvar_import_events.event.sitemap-mirror-index-ready' => 'Индекс карты сайта готов',
            'seasonvar_import_events.event.sitemap-xml-failed' => 'XML карты сайта завершился ошибкой',
            'seasonvar_import_events.event.source-page-crawl-metadata-updated' => 'Данные обхода страницы обновлены',
            'seasonvar_import_events.event.source-page-selected' => 'Страница источника выбрана',
            'seasonvar_import_events.event.source-page-updated' => 'Страница источника обновлена',
            'seasonvar_import_events.event.source-pages-malformed-cleaned' => 'Некорректные ссылки источника отключены',
            'seasonvar_import_events.event.source-pages-status-backfill-complete' => 'Статусы старых страниц обновлены',
            'seasonvar_import_events.event.source-pages-status-backfill-started' => 'Обновление статусов старых страниц началось',
            'seasonvar_import_events.event.store-discovered-urls-chunk-complete' => 'Пакет найденных ссылок сохранен',
            'seasonvar_import_events.event.store-discovered-urls-complete' => 'Сохранение найденных ссылок завершено',
            'seasonvar_import_events.event.store-discovered-urls-started' => 'Сохранение найденных ссылок началось',
            'seasonvar_import_events.event.taxonomy-sync-complete' => 'Синхронизация справочников завершена',
            'seasonvar_import_events.event.taxonomy-sync-started' => 'Синхронизация справочников началась',
            'seasonvar_import_events.event.taxonomy-type-synced' => 'Тип справочника синхронизирован',
            'seasonvar_import_events.event.url-normalized' => 'Ссылка нормализована',
            'catalog_title_aliases.type.original' => 'Оригинальное название',
            'catalog_title_aliases.type.alternative' => 'Дополнительное название',
            'catalog_title_aliases.source.seasonvar' => 'Основной источник',
        ];
    }
}
