<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class CatalogStatsPageBuilder
{
    /**
     * @var array<string, int>
     */
    private array $tableCounts = [];

    /**
     * @var array<string, int>
     */
    private array $presentCounts = [];

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
        $publishedTitles = $this->whereCount('catalog_titles', fn (QueryBuilder $query): QueryBuilder => $query->where('is_published', true));
        $totalDatabaseRows = $databaseTables->sum('total');

        return [
            'headlineStats' => [
                ['label' => 'Записей в базе', 'value' => $totalDatabaseRows, 'icon' => 'fa-solid fa-database'],
                ['label' => 'Карточек каталога', 'value' => $catalogTitles, 'icon' => 'fa-solid fa-clapperboard'],
                ['label' => 'Сезонов и серий', 'value' => $seasons + $episodes, 'icon' => 'fa-solid fa-layer-group'],
                ['label' => 'Видео-ссылок', 'value' => $media, 'icon' => 'fa-solid fa-circle-play'],
            ],
            'summarySections' => [
                [
                    'title' => 'Каталог',
                    'icon' => 'fa-solid fa-clapperboard',
                    'rows' => [
                        $this->row('Карточек всего', $catalogTitles),
                        $this->row('Опубликовано', $publishedTitles, $this->percent($publishedTitles, $catalogTitles)),
                        $this->row('С годом выхода', $this->presentCount('catalog_titles', 'year'), $this->percent($this->presentCount('catalog_titles', 'year'), $catalogTitles)),
                        $this->row('С описанием', $this->presentCount('catalog_titles', 'description'), $this->percent($this->presentCount('catalog_titles', 'description'), $catalogTitles)),
                        $this->row('С постером', $this->presentCount('catalog_titles', 'poster_url'), $this->percent($this->presentCount('catalog_titles', 'poster_url'), $catalogTitles)),
                        $this->row('С оригинальным названием', $this->presentCount('catalog_titles', 'original_title'), $this->percent($this->presentCount('catalog_titles', 'original_title'), $catalogTitles)),
                        $this->row('С внешним ID', $this->presentCount('catalog_titles', 'external_id'), $this->percent($this->presentCount('catalog_titles', 'external_id'), $catalogTitles)),
                        $this->row('Без опубликованного видео', CatalogTitle::query()->whereDoesntHave('licensedMedia', fn (EloquentBuilder $query): EloquentBuilder => $query->where('status', 'published'))->count()),
                    ],
                ],
                [
                    'title' => 'Сезоны и серии',
                    'icon' => 'fa-solid fa-list-ol',
                    'rows' => [
                        $this->row('Сезонов', $seasons),
                        $this->row('Серий', $episodes),
                        $this->row('Среднее сезонов на карточку', $this->average($seasons, $catalogTitles)),
                        $this->row('Среднее серий на сезон', $this->average($episodes, $seasons)),
                        $this->row('Среднее серий на карточку', $this->average($episodes, $catalogTitles)),
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
                        $this->row('Связано с карточкой', $this->presentCount('licensed_media', 'catalog_title_id'), $this->percent($this->presentCount('licensed_media', 'catalog_title_id'), $media)),
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
            'qualitySections' => $this->qualitySections($catalogTitles, $seasons, $episodes, $media, $sourcePages),
            'timeWindowRows' => $this->timeWindowRows(),
            'recentImportRuns' => $this->recentImportRuns(),
            'groupSections' => $this->groupSections(),
            'taxonomyRows' => $this->taxonomyRows(),
            'databaseTables' => $databaseTables,
            'seo' => [
                'title' => 'Сводка каталога',
                'description' => 'Сводка каталога: карточки, сезоны, серии, видео, отзывы, оценки, справочники и обновления.',
                'canonical' => route('stats'),
                'robots' => 'noindex,nofollow',
                'extended_seo' => false,
                'breadcrumbs' => [
                    ['name' => 'Главная', 'url' => route('home')],
                    ['name' => 'Сводка каталога', 'url' => route('stats')],
                ],
            ],
        ];
    }

    /**
     * @return list<array{title: string, icon: string, rows: list<array{label: string, value: int, display: string, meta: string, severity: string, severity_label: string, row_class: string, value_class: string}>}>
     */
    private function qualitySections(int $catalogTitles, int $seasons, int $episodes, int $media, int $sourcePages): array
    {
        return [
            [
                'title' => 'Качество карточек',
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
                    $this->qualityRow('Недоступны по проверке', $this->whereCount('licensed_media', fn (QueryBuilder $query): QueryBuilder => $query->whereIn('check_status', ['unavailable', 'check_failed', 'invalid_url'])), $media, 'critical'),
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
     * @return Collection<int, array{id: string, mode: string, status: string, started_at: string, finished_at: string, selected: string, parsed: string, failed: string, media: string}>
     */
    private function recentImportRuns(): Collection
    {
        return SeasonvarImportRun::query()
            ->latest('id')
            ->limit(10)
            ->get(['id', 'mode', 'status', 'selected', 'parsed', 'failed', 'media_attached', 'media_updated', 'started_at', 'finished_at'])
            ->map(fn (SeasonvarImportRun $run): array => [
                'id' => '#'.$run->id,
                'mode' => $this->displayValue('seasonvar_import_runs', 'mode', $run->mode),
                'status' => $this->displayValue('seasonvar_import_runs', 'status', $run->status),
                'started_at' => $this->dateValue($run->started_at),
                'finished_at' => $this->dateValue($run->finished_at),
                'selected' => $this->formatStat((int) $run->selected),
                'parsed' => $this->formatStat((int) $run->parsed),
                'failed' => $this->formatStat((int) $run->failed),
                'media' => $this->formatStat((int) $run->media_attached).' / '.$this->formatStat((int) $run->media_updated),
            ]);
    }

    /**
     * @return list<array{title: string, icon: string, rows: Collection<int, array{label: string, value: mixed, total: int}>}>
     */
    private function groupSections(): array
    {
        return [
            ['title' => 'Карточки по типам', 'icon' => 'fa-solid fa-shapes', 'rows' => $this->groupedCounts('catalog_titles', 'type')],
            ['title' => 'Карточки по годам', 'icon' => 'fa-solid fa-calendar-days', 'rows' => $this->groupedCounts('catalog_titles', 'year')],
            ['title' => 'Страницы источника по типам', 'icon' => 'fa-solid fa-file-lines', 'rows' => $this->groupedCounts('source_pages', 'page_type')],
            ['title' => 'Страницы источника по состоянию сбора', 'icon' => 'fa-solid fa-code-branch', 'rows' => $this->groupedCounts('source_pages', 'parse_status')],
            ['title' => 'Страницы источника по состоянию обновления', 'icon' => 'fa-solid fa-rotate', 'rows' => $this->groupedCounts('source_pages', 'import_status')],
            ['title' => 'Ответы источника', 'icon' => 'fa-solid fa-server', 'rows' => $this->groupedCounts('source_pages', 'http_status')],
            ['title' => 'Видео по готовности', 'icon' => 'fa-solid fa-signal', 'rows' => $this->groupedCounts('licensed_media', 'status')],
            ['title' => 'Видео по проверке', 'icon' => 'fa-solid fa-shield-halved', 'rows' => $this->groupedCounts('licensed_media', 'check_status')],
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
            $this->row('Первое добавление карточки', $this->dateValue(DB::table('catalog_titles')->min('indexed_at'))),
            $this->row('Последнее добавление карточки', $this->dateValue(DB::table('catalog_titles')->max('indexed_at'))),
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
     * @return Collection<int, array{label: string, value: mixed, total: int, total_display: string, meta?: string|null}>
     */
    private function ratingProviderRows(): Collection
    {
        return DB::table('catalog_title_ratings')
            ->selectRaw('provider as value, COUNT(*) as total, AVG(rating) as average_rating')
            ->groupBy('provider')
            ->orderByDesc('total')
            ->orderBy('provider')
            ->get()
            ->map(fn (object $row): array => [
                'label' => $this->displayValue('catalog_title_ratings', 'provider', $row->value),
                'value' => $row->value,
                'total' => (int) $row->total,
                'total_display' => $this->formatStat((int) $row->total),
                'meta' => $row->average_rating === null ? null : 'средняя '.number_format((float) $row->average_rating, 2, '.', ' '),
            ]);
    }

    /**
     * @return Collection<int, array{label: string, value: mixed, total: int, total_display: string}>
     */
    private function missingDataFlagRows(): Collection
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
            ->map(fn (int $total, string $flag): array => [
                'label' => $this->displayValue('source_pages', 'missing_data_flags', $flag),
                'value' => $flag,
                'total' => $total,
                'total_display' => $this->formatStat($total),
            ])
            ->values();
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
     * @return Collection<int, array{label: string, value: mixed, total: int, total_display: string}>
     */
    private function groupedCounts(string $table, string $column): Collection
    {
        return DB::table($table)
            ->selectRaw($column.' as value, COUNT(*) as total')
            ->groupBy($column)
            ->orderByDesc('total')
            ->orderBy($column)
            ->get()
            ->map(fn (object $row): array => [
                'label' => $this->displayValue($table, $column, $row->value),
                'value' => $row->value,
                'total' => (int) $row->total,
                'total_display' => $this->formatStat((int) $row->total),
            ]);
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
     * @return array{label: string, value: int, display: string, meta: string, severity: string, severity_label: string, row_class: string, value_class: string}
     */
    private function qualityRow(string $label, int $value, int $total, string $severity): array
    {
        $severityClasses = $this->severityClasses($severity);

        return [
            'label' => $label,
            'value' => $value,
            'display' => $this->formatStat($value),
            'meta' => $this->percent($value, $total),
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
            'catalog_titles' => 'Карточки каталога',
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

    private function displayValue(string $table, string $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Не указано';
        }

        $key = $table.'.'.$column.'.'.$value;
        $labels = $this->valueLabels();

        if (isset($labels[$key])) {
            return $labels[$key];
        }

        if ($value === 0 || $value === '0') {
            return '0';
        }

        if ($value === 1 || $value === '1') {
            return '1';
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
            'source_pages.page_type.sitemap' => 'Карта сайта',
            'source_pages.page_type.serial' => 'Страница сериала',
            'source_pages.page_type.actor' => 'Актер',
            'source_pages.page_type.genre' => 'Жанр',
            'source_pages.page_type.country' => 'Страна',
            'source_pages.page_type.tag' => 'Тег',
            'source_pages.page_type.static' => 'Служебная страница',
            'source_pages.page_type.rss' => 'Лента RSS',
            'source_pages.page_type.search' => 'Поиск',
            'source_pages.page_type.unknown' => 'Не определено',
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
            'source_pages.missing_data_flags.video' => 'Не хватает видео',
            'source_pages.missing_data_flags.media' => 'Не хватает видео',
            'licensed_media.status.draft' => 'Готовится',
            'licensed_media.status.published' => 'Готово к просмотру',
            'licensed_media.status.unavailable' => 'Недоступно',
            'licensed_media.check_status.not_checked' => 'Ожидает проверки',
            'licensed_media.check_status.available' => 'Доступно',
            'licensed_media.check_status.unavailable' => 'Недоступно',
            'licensed_media.check_status.check_failed' => 'Проверка не удалась',
            'licensed_media.check_status.invalid_url' => 'Некорректная ссылка',
            'licensed_media.storage_disk.external' => 'Внешний источник',
            'seasonvar_import_runs.status.running' => 'Выполняется',
            'seasonvar_import_runs.status.completed' => 'Завершено',
            'seasonvar_import_runs.status.failed' => 'Ошибка',
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
            'seasonvar_import_events.event.seasonvar-import-complete' => 'Обновление Seasonvar завершено',
            'seasonvar_import_events.event.seasonvar-import-cycle-complete' => 'Цикл обновления завершен',
            'seasonvar_import_events.event.seasonvar-import-cycle-started' => 'Цикл обновления начался',
            'seasonvar_import_events.event.seasonvar-import-failed' => 'Обновление Seasonvar завершилось ошибкой',
            'seasonvar_import_events.event.seasonvar-import-season-url-failed' => 'Страница сезона завершилась ошибкой',
            'seasonvar_import_events.event.seasonvar-import-season-urls-selected' => 'Страницы сезонов выбраны',
            'seasonvar_import_events.event.seasonvar-import-started' => 'Обновление Seasonvar запущено',
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
            'seasonvar_import_events.event.seasonvar-title-merge-complete' => 'Объединение карточек завершено',
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
            'catalog_title_aliases.source.seasonvar' => 'Seasonvar',
        ];
    }
}
