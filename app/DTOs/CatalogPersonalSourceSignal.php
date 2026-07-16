<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogPersonalEvidence;
use App\Enums\CatalogRecommendationReason;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class CatalogPersonalSourceSignal
{
    /**
     * @param  list<CatalogPersonalEvidence>  $evidence
     * @param  list<CatalogRecommendationReason>  $reasonCodes
     */
    private function __construct(
        public int $titleId,
        public int $confidence,
        public array $evidence,
        public array $reasonCodes,
        public ?CarbonImmutable $lastActivityAt,
    ) {}

    /**
     * @param  list<CatalogPersonalEvidence>  $evidence
     * @param  list<CatalogRecommendationReason>  $reasonCodes
     */
    public static function make(
        int $titleId,
        int|float $confidence,
        array $evidence,
        array $reasonCodes,
        ?CarbonInterface $lastActivityAt = null,
    ): self {
        if ($titleId < 1) {
            throw new InvalidArgumentException('Recommendation source title ID must be positive.');
        }

        $cap = max(1, (int) config('recommendations.personalized_v2.source_confidence_cap', 320));

        return new self(
            titleId: $titleId,
            confidence: max(0, min($cap, (int) round($confidence))),
            evidence: self::uniqueEnums($evidence),
            reasonCodes: self::uniqueEnums($reasonCodes),
            lastActivityAt: $lastActivityAt?->toImmutable(),
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  list<T>  $values
     * @return list<T>
     */
    private static function uniqueEnums(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            $unique[$value->value] ??= $value;
        }

        return array_values($unique);
    }
}
