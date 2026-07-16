<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationExplanation;
use App\Enums\CatalogRecommendationReason;
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

    /** @return list<CatalogRecommendationExplanation> */
    public function storedSimilarityExplanations(mixed $storedReasons): array
    {
        $reasons = is_array($storedReasons) ? $storedReasons : [];

        return collect($reasons)
            ->map(fn (mixed $details, string $reason): array => [
                'reason' => $reason,
                'score' => is_array($details) ? max(0, (int) ($details['score'] ?? 0)) : 0,
            ])
            ->sort(function (array $left, array $right): int {
                $score = $right['score'] <=> $left['score'];

                return $score !== 0 ? $score : $left['reason'] <=> $right['reason'];
            })
            ->map(fn (array $item): ?CatalogRecommendationExplanation => $this->storedExplanation($item['reason']))
            ->filter()
            ->unique(fn (CatalogRecommendationExplanation $explanation): string => $explanation->reason->value.'|'.json_encode(
                $explanation->parameters,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ))
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
            'collection_signal',
        ], true) ? $reason : null;
    }

    private function storedExplanation(string $reason): ?CatalogRecommendationExplanation
    {
        if (str_starts_with($reason, 'theme_')) {
            $theme = __("recommendations.similarity_reasons.{$reason}");

            if ($theme === "recommendations.similarity_reasons.{$reason}") {
                return null;
            }

            return new CatalogRecommendationExplanation(
                CatalogRecommendationReason::SimilarTheme,
                ['theme' => $theme],
            );
        }

        $mapped = match ($reason) {
            'genre' => CatalogRecommendationReason::SimilarGenres,
            'tag' => CatalogRecommendationReason::SimilarTags,
            'director' => CatalogRecommendationReason::SharedDirector,
            'actor' => CatalogRecommendationReason::SharedActor,
            'network' => CatalogRecommendationReason::SharedNetwork,
            'studio' => CatalogRecommendationReason::SharedStudio,
            'translation' => CatalogRecommendationReason::SharedTranslation,
            'country' => CatalogRecommendationReason::SameCountry,
            'status' => CatalogRecommendationReason::SameStatus,
            'age_rating' => CatalogRecommendationReason::SimilarAgeRating,
            'year' => CatalogRecommendationReason::NearbyYear,
            'source_signal' => CatalogRecommendationReason::ImportedRelation,
            'collection_signal' => CatalogRecommendationReason::SharedEditorialCollection,
            default => null,
        };

        return $mapped !== null ? new CatalogRecommendationExplanation($mapped) : null;
    }
}
