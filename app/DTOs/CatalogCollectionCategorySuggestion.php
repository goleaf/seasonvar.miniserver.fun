<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogCollectionCategorySuggestionConfidence;

final readonly class CatalogCollectionCategorySuggestion
{
    /**
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public string $collectionPublicId,
        public int $expectedContentVersion,
        public ?string $categoryPublicId,
        public ?string $categorySlug,
        public ?string $categoryPath,
        public int $score,
        public CatalogCollectionCategorySuggestionConfidence $confidence,
        public array $reasonCodes,
        public int $sampleSize,
        public int $totalItems,
    ) {}

    public function isSuggested(): bool
    {
        return $this->categoryPublicId !== null
            && $this->categorySlug !== null
            && $this->confidence !== CatalogCollectionCategorySuggestionConfidence::None;
    }
}
