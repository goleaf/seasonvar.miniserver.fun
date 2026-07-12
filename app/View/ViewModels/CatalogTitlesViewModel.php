<?php

namespace App\View\ViewModels;

use App\Enums\CatalogSort;
use App\Models\CatalogTitle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CatalogTitlesViewModel
{
    /**
     * @var array<string, string>
     */
    public array $typeLabels = [
        'genre' => 'Жанры',
        'country' => 'Страны',
        'actor' => 'Актеры',
        'director' => 'Режиссеры',
        'age_rating' => 'Возрастной рейтинг',
        'translation' => 'Перевод',
        'status' => 'Статус',
        'network' => 'Каналы',
        'studio' => 'Студии',
        'tag' => 'Теги',
    ];

    /**
     * @var array<string, string>
     */
    public array $typeIcons = [
        'genre' => 'fa-solid fa-masks-theater',
        'country' => 'fa-solid fa-earth-europe',
        'actor' => 'fa-solid fa-user-group',
        'director' => 'fa-solid fa-video',
        'age_rating' => 'fa-solid fa-shield-halved',
        'translation' => 'fa-solid fa-language',
        'status' => 'fa-solid fa-signal',
        'network' => 'fa-solid fa-tower-broadcast',
        'studio' => 'fa-solid fa-building',
        'tag' => 'fa-solid fa-tag',
    ];

    /** @var array<string, string> */
    public array $sortLabels;

    /** @var array<string, string> */
    public array $sortIcons;

    /** @var array<string, mixed> */
    public array $baseQuery;

    /** @var array<string, mixed> */
    public array $allFilterSlugs;

    /** @var array<string, list<string>> */
    public array $selectedFilterSlugs;

    /** @var array<string, mixed> */
    public array $catalogQueryState;

    /** @var Collection<string, Collection<int, Model>> */
    public readonly Collection $excludedTaxonomies;

    /** @var list<string> */
    public array $alphabet;

    public ?string $activeLetter;

    /**
     * @var array<string, string|int|null>
     */
    public array $withoutYearQuery;

    /**
     * @var array<string, string|int|null>
     */
    public array $withoutTitleQuery;

    /**
     * @var array<string, string|int|null>
     */
    public array $withoutSearchQuery;

    /**
     * @var array<string, string|int|null>
     */
    public array $withoutFiltersQuery;

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @param  Collection<string, Collection<int, Model>>  $selectedTaxonomies
     * @param  array<string, string>  $activeFilterSlugs
     * @param  array<string, list<string>>  $selectedFilterSlugs
     * @param  array<string, string>  $invalidFilterSlugs
     */
    public function __construct(
        public readonly string $search,
        public readonly string $sort,
        public readonly ?int $year,
        public readonly string $requestedYear,
        public readonly bool $invalidYear,
        public readonly Collection $activeTaxonomies,
        public readonly Collection $selectedTaxonomies,
        public readonly array $activeFilterSlugs,
        public readonly array $invalidFilterSlugs,
        public readonly ?CatalogTitle $titleContext,
        array $selectedFilterSlugs = [],
        public readonly string $view = 'grid',
        public readonly int $perPage = 24,
        array $catalogQueryState = [],
        ?Collection $excludedTaxonomies = null,
    ) {
        $sorts = collect(CatalogSort::cases())->mapWithKeys(fn (CatalogSort $option): array => [$option->value => $option]);
        $this->sortLabels = $sorts->mapWithKeys(fn (CatalogSort $option): array => [$option->value => $option->label()])->all();
        $this->sortIcons = $sorts->mapWithKeys(fn (CatalogSort $option): array => [$option->value => $option->icon()])->all();
        $this->selectedFilterSlugs = $selectedFilterSlugs;
        $this->catalogQueryState = $catalogQueryState;
        $this->excludedTaxonomies = $excludedTaxonomies ?? collect();
        $this->alphabet = ['#', 'latin', ...mb_str_split('АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ')];
        $letter = $this->catalogQueryState['letter'] ?? null;
        $this->activeLetter = is_scalar($letter) ? mb_strtoupper((string) $letter) : null;
        $this->baseQuery = $this->titleContext === null ? [] : ['title' => $this->titleContext->slug];
        $this->allFilterSlugs = array_merge($this->selectedFilterSlugs, $this->invalidFilterSlugs);
        $this->withoutYearQuery = $this->buildWithoutYearQuery();
        $this->withoutTitleQuery = $this->buildWithoutTitleQuery();
        $this->withoutSearchQuery = $this->buildWithoutSearchQuery();
        $this->withoutFiltersQuery = $this->buildWithoutFiltersQuery();
    }

    public function icon(string $filterType): string
    {
        if ($filterType === 'title') {
            return 'fa-solid fa-clapperboard';
        }

        return $this->typeIcons[$filterType] ?? 'fa-solid fa-tag';
    }

    public function label(string $filterType): string
    {
        if ($filterType === 'title') {
            return 'Сериал';
        }

        return $this->typeLabels[$filterType] ?? $filterType;
    }

    public function sortIcon(string $sort): string
    {
        return $this->sortIcons[$sort] ?? 'fa-solid fa-arrow-down-wide-short';
    }

    public function sortLabel(string $sort): string
    {
        return $this->sortLabels[$sort] ?? $this->sortLabels['updated'];
    }

    public function isActiveSort(string $sort): bool
    {
        return $this->sort === $sort;
    }

    public function isActiveLetter(string $letter): bool
    {
        return $this->activeLetter === mb_strtoupper($letter);
    }

    /** @return array<string, mixed> */
    public function alphabetQuery(string $letter): array
    {
        $query = $this->sortQuery($this->sort);

        if ($this->isActiveLetter($letter)) {
            unset($query['letter']);
        } else {
            $query['letter'] = $letter;
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function searchFormState(): array
    {
        $query = $this->withCatalogState($this->baseQuery);

        if ($this->sort !== 'updated') {
            $query['sort'] = $this->sort;
        }

        if ($this->view !== 'grid') {
            $query['view'] = $this->view;
        }

        if ($this->perPage !== 24) {
            $query['per_page'] = $this->perPage;
        }

        return $query;
    }

    public function hasActiveFilters(): bool
    {
        $filterKeys = [
            'year',
            'exclude_country',
            'exclude_genre',
            'year_from',
            'year_to',
            'seasons_min',
            'seasons_max',
            'episodes_min',
            'episodes_max',
            'rating_source',
            'rating_min',
            'votes_min',
            'video',
            'subtitles',
            'quality',
            'updated',
            'letter',
        ];

        return $this->selectedTaxonomies->isNotEmpty()
            || $this->invalidFilterSlugs !== []
            || collect($filterKeys)->contains(fn (string $key): bool => array_key_exists($key, $this->catalogQueryState));
    }

    /** @return array<string, mixed> */
    public function viewQuery(string $view): array
    {
        $query = $this->sortQuery($this->sort);

        if ($view === 'grid') {
            unset($query['view']);
        } else {
            $query['view'] = $view;
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function perPageQuery(int $perPage): array
    {
        $query = $this->sortQuery($this->sort);

        if ($perPage === 24) {
            unset($query['per_page']);
        } else {
            $query['per_page'] = $perPage;
        }

        return $query;
    }

    /** @return list<array{key: string, label: string, value: string}> */
    public function advancedFilterChips(): array
    {
        $labels = [
            'year' => 'Годы',
            'year_from' => 'Год от',
            'year_to' => 'Год до',
            'seasons_min' => 'Сезонов от',
            'seasons_max' => 'Сезонов до',
            'episodes_min' => 'Серий от',
            'episodes_max' => 'Серий до',
            'rating_source' => 'Источник рейтинга',
            'rating_min' => 'Рейтинг от',
            'votes_min' => 'Голосов от',
            'video' => 'Видео',
            'subtitles' => 'Субтитры',
            'quality' => 'Качество',
            'updated' => 'Обновлено',
            'letter' => 'Буква',
        ];

        return collect($labels)
            ->filter(fn (string $label, string $key): bool => array_key_exists($key, $this->catalogQueryState)
                && ! ($key === 'year' && $this->year !== null))
            ->map(function (string $label, string $key): array {
                $value = $this->catalogQueryState[$key];

                return ['key' => $key, 'label' => $label, 'value' => $this->advancedFilterValue($key, $value)];
            })
            ->values()
            ->all();
    }

    private function advancedFilterValue(string $key, mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', $value);
        }

        return match ($key) {
            'updated' => match ($value) {
                'day' => 'за день',
                'week' => 'за неделю',
                'month' => 'за месяц',
                'year' => 'за год',
                default => (string) $value,
            },
            'video', 'subtitles' => $value === 'available' ? 'есть' : 'нет',
            'rating_source' => $value === 'kinopoisk' ? 'КиноПоиск' : 'IMDb',
            default => (string) $value,
        };
    }

    /** @return array<string, mixed> */
    public function withoutCatalogState(string $key): array
    {
        $query = $this->sortQuery($this->sort);
        unset($query[$key]);

        return $query;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function sortQuery(string $sort): array
    {
        $query = $this->appendSearchAndYear(array_merge($this->baseQuery, $this->allFilterSlugs));

        if ($sort !== 'updated') {
            $query['sort'] = $sort;
        } else {
            unset($query['sort']);
        }

        return $query;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function filterQuery(string $filterType, ?string $slug = null): array
    {
        $query = $this->withCatalogState(array_merge($this->baseQuery, $this->allFilterSlugs));

        if ($slug === null) {
            unset($query[$filterType]);
        } else {
            $values = is_array($query[$filterType] ?? null) ? $query[$filterType] : [];

            if (in_array($slug, $values, true)) {
                $values = array_values(array_diff($values, [$slug]));
            } else {
                $values[] = $slug;
            }

            if ($values === []) {
                unset($query[$filterType]);
            } else {
                $query[$filterType] = array_values(array_unique($values));
            }
        }

        return $this->appendSearchAndYear($query);
    }

    /** @return array<string, mixed> */
    public function exclusionQuery(string $filterType, string $slug): array
    {
        $key = 'exclude_'.$filterType;
        $query = $this->sortQuery($this->sort);
        $values = is_array($query[$key] ?? null) ? $query[$key] : [];
        $values = array_values(array_diff($values, [$slug]));

        if ($values === []) {
            unset($query[$key]);
        } else {
            $query[$key] = $values;
        }

        return $query;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function yearQuery(?int $selectedYear): array
    {
        $query = $this->withCatalogState(array_merge($this->baseQuery, $this->allFilterSlugs));
        unset($query['year']);

        if ($this->search !== '') {
            $query['q'] = $this->search;
        }

        if ($this->sort !== 'updated') {
            $query['sort'] = $this->sort;
        }

        if ($selectedYear !== null) {
            $query['year'] = $selectedYear;
        }

        return $query;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function invalidFilterQuery(string $filterType): array
    {
        $query = array_merge($this->baseQuery, $this->allFilterSlugs);
        unset($query[$filterType]);

        return $this->appendSearchAndYear($query);
    }

    public function bucketYear(object $bucket): int
    {
        return (int) $bucket->year;
    }

    public function isActiveYear(object $bucket): bool
    {
        return $this->year === $this->bucketYear($bucket);
    }

    public function currentTaxonomy(string $filterType): ?Model
    {
        $taxonomy = $this->activeTaxonomies->get($filterType);

        return $taxonomy instanceof Model ? $taxonomy : null;
    }

    public function isActiveTaxonomy(string $filterType, Model $taxonomy): bool
    {
        return $this->currentTaxonomy($filterType)?->getKey() === $taxonomy->getKey();
    }

    /**
     * @param  array<string, string|int|null>  $query
     * @return array<string, string|int|null>
     */
    private function appendSearchAndYear(array $query): array
    {
        $query = $this->withCatalogState($query);

        if ($this->search !== '') {
            $query['q'] = $this->search;
        }

        if ($this->sort !== 'updated') {
            $query['sort'] = $this->sort;
        }

        if ($this->year !== null) {
            $query['year'] = $this->year;
        }

        if ($this->invalidYear) {
            $query['year'] = $this->requestedYear;
        }

        if ($this->view !== 'grid') {
            $query['view'] = $this->view;
        }

        if ($this->perPage !== 24) {
            $query['per_page'] = $this->perPage;
        }

        return $query;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function buildWithoutYearQuery(): array
    {
        $query = $this->withCatalogState(array_merge($this->baseQuery, $this->allFilterSlugs));
        unset($query['year']);

        if ($this->search !== '') {
            $query['q'] = $this->search;
        }

        if ($this->sort !== 'updated') {
            $query['sort'] = $this->sort;
        }

        return $query;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function buildWithoutTitleQuery(): array
    {
        return $this->appendSearchAndYear($this->allFilterSlugs);
    }

    /**
     * @return array<string, string|int|null>
     */
    private function buildWithoutSearchQuery(): array
    {
        $query = $this->withCatalogState(array_merge($this->baseQuery, $this->allFilterSlugs));

        if ($this->sort !== 'updated') {
            $query['sort'] = $this->sort;
        }

        if ($this->year !== null) {
            $query['year'] = $this->year;
        } elseif ($this->invalidYear) {
            $query['year'] = $this->requestedYear;
        }

        return $query;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function buildWithoutFiltersQuery(): array
    {
        $query = [];

        if ($this->search !== '') {
            $query['q'] = $this->search;
        }

        if ($this->sort !== 'updated') {
            $query['sort'] = $this->sort;
        }

        if ($this->view !== 'grid') {
            $query['view'] = $this->view;
        }

        if ($this->perPage !== 24) {
            $query['per_page'] = $this->perPage;
        }

        return $query;
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function withCatalogState(array $query): array
    {
        return array_merge($this->catalogQueryState, $query);
    }
}
