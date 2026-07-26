<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Enums\CatalogRecommendationPlaybackPreference;
use Carbon\CarbonImmutable;

final readonly class CatalogRecommendationPreferenceData
{
    /**
     * @param  list<int>  $hiddenGenreIds
     * @param  list<int>  $preferredGenreIds
     * @param  list<int>  $preferredCountryIds
     * @param  list<int>  $likedTitleIds
     * @param  list<int>  $excludedTitleIds
     */
    public function __construct(
        public CatalogRecommendationDiversityPreference $diversity,
        public CatalogRecommendationFreshnessPreference $freshness,
        public ?CarbonImmutable $profileResetAt,
        public array $hiddenGenreIds,
        public CatalogRecommendationPlaybackPreference $playbackPreference = CatalogRecommendationPlaybackPreference::Any,
        public CatalogRecommendationCompletionPreference $completionPreference = CatalogRecommendationCompletionPreference::Any,
        public CatalogRecommendationEpisodeLengthPreference $episodeLengthPreference = CatalogRecommendationEpisodeLengthPreference::Any,
        public ?CarbonImmutable $onboardingCompletedAt = null,
        public array $preferredGenreIds = [],
        public array $preferredCountryIds = [],
        public array $likedTitleIds = [],
        public array $excludedTitleIds = [],
    ) {}

    public static function defaults(): self
    {
        return new self(
            diversity: CatalogRecommendationDiversityPreference::Balanced,
            freshness: CatalogRecommendationFreshnessPreference::Balanced,
            profileResetAt: null,
            hiddenGenreIds: [],
            playbackPreference: CatalogRecommendationPlaybackPreference::Any,
            completionPreference: CatalogRecommendationCompletionPreference::Any,
            episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Any,
            onboardingCompletedAt: null,
            preferredGenreIds: [],
            preferredCountryIds: [],
            likedTitleIds: [],
            excludedTitleIds: [],
        );
    }
}
