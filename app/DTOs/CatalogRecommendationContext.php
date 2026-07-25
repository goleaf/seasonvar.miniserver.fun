<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogPopularityPeriod;
use App\Enums\CatalogRecommendationType;
use App\Models\User;

final readonly class CatalogRecommendationContext
{
    /**
     * @param  list<int>  $excludedTitleIds
     * @param  array<string, scalar|list<scalar>|null>  $filters
     */
    public function __construct(
        public CatalogRecommendationType $type,
        public ?User $user,
        public string $locale,
        public ?int $currentTitleId = null,
        public array $excludedTitleIds = [],
        public array $filters = [],
        public CatalogPopularityPeriod $period = CatalogPopularityPeriod::Week,
        public string $ratingSource = 'portal',
        public int $page = 1,
        public int $perPage = 24,
        public ?string $seed = null,
    ) {}

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function boundedPerPage(): int
    {
        return max(1, min(48, $this->perPage));
    }

    public function boundedPage(): int
    {
        if ($this->type === CatalogRecommendationType::Random) {
            return 1;
        }

        return max(1, min(500, $this->page));
    }

    public function withType(CatalogRecommendationType $type): self
    {
        return new self(
            type: $type,
            user: $this->user,
            locale: $this->locale,
            currentTitleId: $this->currentTitleId,
            excludedTitleIds: $this->excludedTitleIds,
            filters: $this->filters,
            period: $this->period,
            ratingSource: $this->ratingSource,
            page: 1,
            perPage: $this->perPage,
            seed: null,
        );
    }

    public function withTypeAndPeriod(
        CatalogRecommendationType $type,
        CatalogPopularityPeriod $period,
    ): self {
        $context = $this->withType($type);

        return new self(
            type: $context->type,
            user: $context->user,
            locale: $context->locale,
            currentTitleId: $context->currentTitleId,
            excludedTitleIds: $context->excludedTitleIds,
            filters: $context->filters,
            period: $period,
            ratingSource: $context->ratingSource,
            page: $context->page,
            perPage: $context->perPage,
            seed: $context->seed,
        );
    }
}
