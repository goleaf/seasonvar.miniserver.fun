<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogRecommendationScore
{
    /**
     * @param  array<string, array<string, int|float|string>>  $reasons
     * @param  list<string>  $diversityFeatures
     */
    public function __construct(
        public int $metadataScore,
        public int $sourceScore,
        public int $qualityScore,
        public int $matchedFeaturesCount,
        public array $reasons,
        public array $diversityFeatures,
    ) {}

    public function total(): int
    {
        return $this->metadataScore + $this->sourceScore + $this->qualityScore;
    }
}
