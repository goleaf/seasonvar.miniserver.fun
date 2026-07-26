<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationPlaybackPreference;

final readonly class CatalogTasteOnboardingData
{
    /**
     * @param  list<int>  $likedTitleIds
     * @param  list<int>  $excludedTitleIds
     * @param  list<int>  $genreIds
     * @param  list<int>  $countryIds
     */
    public function __construct(
        public array $likedTitleIds,
        public array $excludedTitleIds,
        public array $genreIds,
        public array $countryIds,
        public string $locale,
        public CatalogRecommendationPlaybackPreference $playbackPreference,
        public CatalogRecommendationCompletionPreference $completionPreference,
        public CatalogRecommendationEpisodeLengthPreference $episodeLengthPreference,
    ) {}
}
