<?php

namespace App\Services\Catalog;

use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;
use App\Services\Catalog\Search\CatalogSearchQuery;
use Carbon\CarbonImmutable;

final readonly class CatalogTitlesCriteria
{
    /**
     * @param  list<int>  $years
     * @param  array<string, list<string>>  $filterSlugs
     * @param  array{country: list<string>, genre: list<string>}  $excludedFilterSlugs
     * @param  list<string>  $qualities
     */
    public function __construct(
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
        );
    }

    public function updatedAfter(): ?CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfDay();

        return match ($this->updatedPeriod) {
            'week' => $now->subWeek(),
            'month' => $now->subMonth(),
            'year' => $now->subYear(),
            default => null,
        };
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
        return count($this->years)
            + collect($this->filterSlugs)->sum(fn (array $values): int => count($values))
            + collect($this->excludedFilterSlugs)->sum(fn (array $values): int => count($values))
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

        if (array_key_exists($type, $filterSlugs)) {
            $filterSlugs[$type] = [];
        }

        return $this->copy(filterSlugs: $filterSlugs);
    }

    /**
     * @param  list<int>|null  $years
     * @param  array<string, list<string>>|null  $filterSlugs
     */
    private function copy(?array $years = null, ?array $filterSlugs = null): self
    {
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
        );
    }
}
