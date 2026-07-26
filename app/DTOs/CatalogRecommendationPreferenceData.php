<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use Carbon\CarbonImmutable;

final readonly class CatalogRecommendationPreferenceData
{
    /** @param list<int> $hiddenGenreIds */
    public function __construct(
        public CatalogRecommendationDiversityPreference $diversity,
        public CatalogRecommendationFreshnessPreference $freshness,
        public ?CarbonImmutable $profileResetAt,
        public array $hiddenGenreIds,
    ) {}

    public static function defaults(): self
    {
        return new self(
            diversity: CatalogRecommendationDiversityPreference::Balanced,
            freshness: CatalogRecommendationFreshnessPreference::Balanced,
            profileResetAt: null,
            hiddenGenreIds: [],
        );
    }
}
