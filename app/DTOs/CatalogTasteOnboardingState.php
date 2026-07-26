<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationPlaybackPreference;
use Carbon\CarbonImmutable;

final readonly class CatalogTasteOnboardingState
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
        public CatalogRecommendationPlaybackPreference $playbackPreference,
        public CatalogRecommendationCompletionPreference $completionPreference,
        public CatalogRecommendationEpisodeLengthPreference $episodeLengthPreference,
        public ?CarbonImmutable $completedAt,
    ) {}

    public static function defaults(): self
    {
        return new self(
            likedTitleIds: [],
            excludedTitleIds: [],
            genreIds: [],
            countryIds: [],
            playbackPreference: CatalogRecommendationPlaybackPreference::Any,
            completionPreference: CatalogRecommendationCompletionPreference::Any,
            episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Any,
            completedAt: null,
        );
    }
}
