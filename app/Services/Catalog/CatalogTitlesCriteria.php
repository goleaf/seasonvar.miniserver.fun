<?php

namespace App\Services\Catalog;

use App\Enums\CatalogFilterType;
use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;
use App\Services\Catalog\Search\CatalogSearchQuery;
use Carbon\CarbonImmutable;

final readonly class CatalogTitlesCriteria
{
    private const MAX_SELECTIONS = 20;

    /**
     * @param  list<int>  $years
     * @param  array<string, list<string>>  $filterSlugs
     * @param  array{country: list<string>, genre: list<string>}  $excludedFilterSlugs
     * @param  list<string>  $qualities
     * @param  array<string, list<int>>  $selectedTaxonomyIds
     * @param  array<string, list<int>>  $excludedTaxonomyIds
     */
    private function __construct(
        public CatalogSearchQuery $search,
        public array $years,
        public array $filterSlugs,
        public array $excludedFilterSlugs,
        public ?int $yearFrom,
        public ?int $yearTo,
        public ?int $seasonsMin,
        public ?int $seasonsMax,
        public ?int $episodesMin,
        public ?int $episodesMax,
        public ?string $ratingSource,
        public ?float $ratingMin,
        public ?int $votesMin,
        public ?string $videoAvailability,
        public ?string $subtitleAvailability,
        public array $qualities,
        public ?string $updatedPeriod,
        public ?string $letter,
        public string $view,
        public int $perPage,
        public CatalogSort $sort,
        public ?int $titleContextId,
        public bool $invalidTitleContext,
        public array $selectedTaxonomyIds,
        public array $excludedTaxonomyIds,
        public bool $invalidYear,
    ) {}

    public static function fromRequest(
        CatalogTitlesRequest $request,
        CatalogSearchQuery $search,
        ?int $titleContextId,
        bool $invalidTitleContext,
    ): self {
        return new self(
            search: $search,
            years: $request->years(),
            filterSlugs: $request->filterSlugs(),
            excludedFilterSlugs: $request->excludedFilterSlugs(),
            yearFrom: $request->yearFrom(),
            yearTo: $request->yearTo(),
            seasonsMin: $request->seasonsMin(),
            seasonsMax: $request->seasonsMax(),
            episodesMin: $request->episodesMin(),
            episodesMax: $request->episodesMax(),
            ratingSource: $request->ratingSource(),
            ratingMin: $request->ratingMin(),
            votesMin: $request->votesMin(),
            videoAvailability: $request->videoAvailability(),
            subtitleAvailability: $request->subtitleAvailability(),
            qualities: $request->qualities(),
            updatedPeriod: $request->updatedPeriod(),
            letter: $request->letter(),
            view: $request->view(),
            perPage: $request->perPage(),
            sort: $request->sort(),
            titleContextId: $titleContextId,
            invalidTitleContext: $invalidTitleContext,
            selectedTaxonomyIds: [],
            excludedTaxonomyIds: [],
            invalidYear: $request->invalidYear(),
        );
    }

    /**
     * @param  array<string, list<int>>  $selected
     * @param  array<string, list<int>>  $excluded
     */
    public function withResolvedTaxonomies(array $selected, array $excluded, bool $invalidYear): self
    {
        return $this->copy(
            selectedTaxonomyIds: $this->normalizeTaxonomyIds($selected),
            excludedTaxonomyIds: $this->normalizeTaxonomyIds($excluded),
            invalidYear: $invalidYear,
        );
    }

    public function updatedAfter(): ?CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfDay();

        return match ($this->updatedPeriod) {
            'day' => $now->subDay(),
            'week' => $now->subWeek(),
            'month' => $now->subMonth(),
            'year' => $now->subYear(),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function queryFilters(): array
    {
        return [
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'seasons_min' => $this->seasonsMin,
            'seasons_max' => $this->seasonsMax,
            'episodes_min' => $this->episodesMin,
            'episodes_max' => $this->episodesMax,
            'video' => $this->videoAvailability,
            'subtitles' => $this->subtitleAvailability,
            'quality' => $this->qualities,
            'updated_after' => $this->updatedAfter(),
            'letter' => $this->letter,
            'rating_source' => $this->ratingSource,
            'rating_min' => $this->ratingMin,
            'votes_min' => $this->votesMin,
        ];
    }

    public function hasContentFilters(): bool
    {
        return $this->activeFilterCount() > 0
            || $this->yearFrom !== null
            || $this->yearTo !== null
            || $this->seasonsMin !== null
            || $this->seasonsMax !== null
            || $this->episodesMin !== null
            || $this->episodesMax !== null
            || $this->ratingSource !== null
            || $this->ratingMin !== null
            || $this->votesMin !== null
            || $this->videoAvailability !== null
            || $this->subtitleAvailability !== null
            || $this->qualities !== []
            || $this->letter !== null;
    }

    public function activeFilterCount(): int
    {
        $selectedCount = $this->selectedTaxonomyIds === []
            ? collect($this->filterSlugs)->sum(fn (array $values): int => count($values))
            : collect($this->selectedTaxonomyIds)->sum(fn (array $values): int => count($values));
        $excludedCount = $this->excludedTaxonomyIds === []
            ? collect($this->excludedFilterSlugs)->sum(fn (array $values): int => count($values))
            : collect($this->excludedTaxonomyIds)->sum(fn (array $values): int => count($values));

        return count($this->years)
            + $selectedCount
            + $excludedCount
            + ($this->updatedPeriod === null ? 0 : 1)
            + ($this->titleContextId === null && ! $this->invalidTitleContext ? 0 : 1);
    }

    public function withoutYears(): self
    {
        return $this->copy(years: []);
    }

    public function withoutRelation(string $type): self
    {
        $filterSlugs = $this->filterSlugs;
        $selectedTaxonomyIds = $this->selectedTaxonomyIds;
        $excludedTaxonomyIds = $this->excludedTaxonomyIds;

        if (array_key_exists($type, $filterSlugs)) {
            $filterSlugs[$type] = [];
        }

        $selectedTaxonomyIds[$type] = [];
        $excludedTaxonomyIds[$type] = [];

        return $this->copy(
            filterSlugs: $filterSlugs,
            selectedTaxonomyIds: $selectedTaxonomyIds,
            excludedTaxonomyIds: $excludedTaxonomyIds,
        );
    }

    /**
     * @param  list<int>|null  $years
     * @param  array<string, list<string>>|null  $filterSlugs
     */
    private function copy(
        ?array $years = null,
        ?array $filterSlugs = null,
        ?array $selectedTaxonomyIds = null,
        ?array $excludedTaxonomyIds = null,
        ?bool $invalidYear = null,
    ): self {
        return new self(
            search: $this->search,
            years: $years ?? $this->years,
            filterSlugs: $filterSlugs ?? $this->filterSlugs,
            excludedFilterSlugs: $this->excludedFilterSlugs,
            yearFrom: $this->yearFrom,
            yearTo: $this->yearTo,
            seasonsMin: $this->seasonsMin,
            seasonsMax: $this->seasonsMax,
            episodesMin: $this->episodesMin,
            episodesMax: $this->episodesMax,
            ratingSource: $this->ratingSource,
            ratingMin: $this->ratingMin,
            votesMin: $this->votesMin,
            videoAvailability: $this->videoAvailability,
            subtitleAvailability: $this->subtitleAvailability,
            qualities: $this->qualities,
            updatedPeriod: $this->updatedPeriod,
            letter: $this->letter,
            view: $this->view,
            perPage: $this->perPage,
            sort: $this->sort,
            titleContextId: $this->titleContextId,
            invalidTitleContext: $this->invalidTitleContext,
            selectedTaxonomyIds: $selectedTaxonomyIds ?? $this->selectedTaxonomyIds,
            excludedTaxonomyIds: $excludedTaxonomyIds ?? $this->excludedTaxonomyIds,
            invalidYear: $invalidYear ?? $this->invalidYear,
        );
    }

    /**
     * @param  array<string, list<int>>  $idsByType
     * @return array<string, list<int>>
     */
    private function normalizeTaxonomyIds(array $idsByType): array
    {
        $supportedTypes = CatalogFilterType::values();

        return collect($idsByType)
            ->filter(fn (mixed $ids, string $type): bool => in_array($type, $supportedTypes, true) && is_array($ids))
            ->map(fn (array $ids): array => collect($ids)
                ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->take(self::MAX_SELECTIONS)
                ->values()
                ->all())
            ->filter(fn (array $ids): bool => $ids !== [])
            ->all();
    }
}
