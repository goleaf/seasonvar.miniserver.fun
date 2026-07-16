<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogPersonalizationConfidence;

final readonly class CatalogPersonalizedCandidateSet
{
    /**
     * @param  list<array{id: int, score: int, source: string, reason: string, reasons?: list<array{reason: string, parameters: array<string, scalar>}>, support_count?: int, normalized_relevance?: float}>  $candidates
     * @param  list<int>  $sourceTitleIds
     */
    public function __construct(
        public array $candidates,
        public CatalogPersonalizationConfidence $confidence,
        public array $sourceTitleIds,
    ) {}

    public static function cold(): self
    {
        return new self([], CatalogPersonalizationConfidence::Cold, []);
    }
}
