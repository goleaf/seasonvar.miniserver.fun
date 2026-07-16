<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationExplanation;
use App\Enums\CatalogRecommendationType;
use App\Enums\CatalogTitleRelationType;

final class CatalogRecommendationPresenter
{
    /** @return array{title: string, description: string, accessibility: string} */
    public function type(CatalogRecommendationType $type): array
    {
        return [
            'title' => __("recommendations.types.{$type->value}.title"),
            'description' => __("recommendations.types.{$type->value}.description"),
            'accessibility' => __("recommendations.types.{$type->value}.accessibility"),
        ];
    }

    public function explanation(CatalogRecommendationExplanation $explanation): string
    {
        return __("recommendations.reasons.{$explanation->reason->value}", $explanation->parameters);
    }

    /**
     * @param  list<CatalogRecommendationExplanation>  $explanations
     * @return list<string>
     */
    public function explanations(array $explanations): array
    {
        return collect($explanations)
            ->map(fn (CatalogRecommendationExplanation $explanation): string => $this->explanation($explanation))
            ->filter(fn (string $label): bool => $label !== '')
            ->unique()
            ->take(4)
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function storedSimilarityReasons(mixed $storedReasons): array
    {
        $reasons = is_array($storedReasons) ? $storedReasons : [];

        return collect($reasons)
            ->map(fn (mixed $details, string $reason): array => [
                'reason' => $reason,
                'score' => is_array($details) ? (int) ($details['score'] ?? 0) : 0,
            ])
            ->reject(fn (array $item): bool => $item['reason'] === 'type')
            ->sortByDesc('score')
            ->map(function (array $item): ?string {
                $key = $this->storedReasonKey($item['reason']);

                return $key !== null ? __("recommendations.similarity_reasons.{$key}") : null;
            })
            ->filter()
            ->unique()
            ->take(4)
            ->values()
            ->all();
    }

    public function relation(CatalogTitleRelationType $type): string
    {
        return __("recommendations.relations.{$type->value}");
    }

    private function storedReasonKey(string $reason): ?string
    {
        if (str_starts_with($reason, 'theme_')) {
            return $reason;
        }

        return in_array($reason, [
            'genre',
            'tag',
            'director',
            'actor',
            'network',
            'studio',
            'translation',
            'status',
            'country',
            'age_rating',
            'year',
            'rating',
            'reviews',
            'published_media',
            'source_signal',
        ], true) ? $reason : null;
    }
}
