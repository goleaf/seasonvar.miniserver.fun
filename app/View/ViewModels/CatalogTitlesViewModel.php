<?php

namespace App\View\ViewModels;

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

    /**
     * @var array<string, string>
     */
    public array $sortLabels = [
        'updated' => 'Недавно обновленные',
        'with_video' => 'Видео: больше сначала',
        'episodes_desc' => 'Серий: больше сначала',
        'year_desc' => 'Год: новые сначала',
        'year_asc' => 'Год: старые сначала',
        'title_asc' => 'Название: А-я',
    ];

    /**
     * @var array<string, string>
     */
    public array $sortIcons = [
        'updated' => 'fa-solid fa-clock-rotate-left',
        'with_video' => 'fa-solid fa-file-video',
        'episodes_desc' => 'fa-solid fa-list-ol',
        'year_desc' => 'fa-solid fa-calendar-days',
        'year_asc' => 'fa-regular fa-calendar',
        'title_asc' => 'fa-solid fa-arrow-down-a-z',
    ];

    /**
     * @var array<string, string>
     */
    public array $baseQuery;

    /**
     * @var array<string, string>
     */
    public array $allFilterSlugs;

    /**
     * @var array<string, string|int|null>
     */
    public array $withoutYearQuery;

    /**
     * @var array<string, string|int|null>
     */
    public array $withoutTitleQuery;

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @param  array<string, string>  $activeFilterSlugs
     * @param  array<string, string>  $invalidFilterSlugs
     */
    public function __construct(
        public readonly string $search,
        public readonly string $sort,
        public readonly ?int $year,
        public readonly string $requestedYear,
        public readonly bool $invalidYear,
        public readonly Collection $activeTaxonomies,
        public readonly array $activeFilterSlugs,
        public readonly array $invalidFilterSlugs,
        public readonly ?CatalogTitle $titleContext,
    ) {
        $this->baseQuery = $this->titleContext === null ? [] : ['title' => $this->titleContext->slug];
        $this->allFilterSlugs = array_merge($this->activeFilterSlugs, $this->invalidFilterSlugs);
        $this->withoutYearQuery = $this->buildWithoutYearQuery();
        $this->withoutTitleQuery = $this->buildWithoutTitleQuery();
    }

    public function icon(string $filterType): string
    {
        return $this->typeIcons[$filterType] ?? 'fa-solid fa-tag';
    }

    public function label(string $filterType): string
    {
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
        $query = array_merge($this->baseQuery, $this->allFilterSlugs);

        if ($slug === null) {
            unset($query[$filterType]);
        } else {
            $query[$filterType] = $slug;
        }

        return $this->appendSearchAndYear($query);
    }

    /**
     * @return array<string, string|int|null>
     */
    public function yearQuery(?int $selectedYear): array
    {
        $query = array_merge($this->baseQuery, $this->allFilterSlugs);

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

        return $query;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function buildWithoutYearQuery(): array
    {
        $query = array_merge($this->baseQuery, $this->allFilterSlugs);

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
}
