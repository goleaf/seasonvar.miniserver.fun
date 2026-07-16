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
        return max(1, min(500, $this->page));
    }
}
