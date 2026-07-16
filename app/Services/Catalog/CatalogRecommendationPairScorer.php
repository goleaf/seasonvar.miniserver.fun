<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationScore;

final class CatalogRecommendationPairScorer
{
    private const EDITORIAL_COLLECTION_SIGNAL_PREFIX = 'editorial_collection:';

    /** @var list<string> */
    private const RELATION_FEATURES = [
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
    ];

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array<int|string, int>>  $documentFrequency
     */
    public function score(
        array $source,
        array $candidate,
        array $documentFrequency,
        int $documentCount,
        ?int $minimumScore = null,
    ): ?CatalogRecommendationScore {
        if ((int) ($candidate['published_media_count'] ?? 0) <= 0) {
            return null;
        }

        $documentCount = max(1, $documentCount);
        $weights = $this->weights();
        $metadataScore = 0;
        $sourceScore = 0;
        $qualityScore = 0;
        $matchedFeaturesCount = 0;
        $reasons = [];
        $diversityFeatures = [];
        $hasStrongMatch = false;

        foreach (self::RELATION_FEATURES as $feature) {
            $sourceIds = $this->relationIds($source, $feature);
            $candidateIds = $this->relationIds($candidate, $feature);
            [$shared, $overlapCoefficient, $jaccard] = $this->overlap($sourceIds, $candidateIds);

            if ($shared === []) {
                continue;
            }

            usort($shared, fn (int $left, int $right): int => $this->idf(
                $documentCount,
                (int) ($documentFrequency[$feature][$right] ?? 1),
            ) <=> $this->idf(
                $documentCount,
                (int) ($documentFrequency[$feature][$left] ?? 1),
            ));
            $contributingIds = array_slice($shared, 0, 3);
            $averageIdf = array_sum(array_map(
                fn (int $id): float => $this->idf(
                    $documentCount,
                    (int) ($documentFrequency[$feature][$id] ?? 1),
                ),
                $contributingIds,
            )) / count($contributingIds);
            $highCardinalityPenalty = in_array($feature, ['actor', 'director'], true)
                ? max(0.35, 1 - (max(count($sourceIds), count($candidateIds)) / 80))
                : 1.0;
            $reasonScore = (int) round(
                ($weights[$feature] ?? 0)
                * $averageIdf
                * (0.5 + (0.5 * $overlapCoefficient))
                * $highCardinalityPenalty,
            );

            if ($reasonScore <= 0) {
                continue;
            }

            $metadataScore += $reasonScore;
            $matchedFeaturesCount++;
            $reasons[$feature] = [
                'count' => count($shared),
                'ratio' => round($overlapCoefficient, 4),
                'jaccard' => round($jaccard, 4),
                'score' => $reasonScore,
            ];

            foreach ($contributingIds as $id) {
                $diversityFeatures[] = $feature.':'.$id;
            }

            if ($this->isStrongRelation($feature, count($shared), $overlapCoefficient)) {
                $hasStrongMatch = true;
            }
        }

        $sourceThemes = $this->themes($source);
        $candidateThemes = $this->themes($candidate);

        foreach (array_values(array_intersect($sourceThemes, $candidateThemes)) as $theme) {
            $reasonScore = (int) round(
                ($weights['theme'] ?? 0)
                * $this->idf($documentCount, (int) ($documentFrequency['theme'][$theme] ?? 1)),
            );

            if ($reasonScore <= 0) {
                continue;
            }

            $metadataScore += $reasonScore;
            $matchedFeaturesCount++;
            $reasons['theme_'.$theme] = ['score' => $reasonScore];
            $diversityFeatures[] = 'theme:'.$theme;
            $hasStrongMatch = true;
        }

        if (($source['type'] ?? null) !== null && ($source['type'] ?? null) === ($candidate['type'] ?? null)) {
            $metadataScore += 45;
            $reasons['type'] = ['score' => 45];
        }

        $yearScore = $this->yearScore($source['year'] ?? null, $candidate['year'] ?? null);

        if ($yearScore > 0) {
            $metadataScore += $yearScore;
            $reasons['year'] = ['score' => $yearScore];
        }

        $candidateId = max(0, (int) ($candidate['id'] ?? 0));
        $providerTargets = is_array($source['provider_targets'] ?? null) ? $source['provider_targets'] : [];
        $providerWeight = max(0, (int) ($providerTargets[$candidateId] ?? 0));

        if ($providerWeight > 0) {
            $providerScore = min(
                max(1, (int) config('recommendations.similarity_v6.provider_relation_score', 650)),
                $providerWeight,
            );
            $sourceScore += $providerScore;
            $reasons['source_signal'] = [
                'target_id' => $candidateId,
                'score' => $providerScore,
            ];
            $matchedFeaturesCount++;
            $hasStrongMatch = true;
        }

        $sharedSignalScore = $this->sharedSignalScore($source, $candidate);

        if ($sharedSignalScore > 0) {
            $sourceScore += $sharedSignalScore;
            $reason = $reasons['source_signal'] ?? ['score' => 0];
            $reason['score'] = (int) $reason['score'] + $sharedSignalScore;
            $reasons['source_signal'] = $reason;
            $matchedFeaturesCount++;
            $hasStrongMatch = true;
        }

        [$collectionSignalScore, $sharedCollectionSignals] = $this->sharedEditorialCollectionScore(
            $source,
            $candidate,
            $documentFrequency,
            $documentCount,
        );

        if ($collectionSignalScore > 0) {
            $sourceScore += $collectionSignalScore;
            $reasons['collection_signal'] = [
                'count' => count($sharedCollectionSignals),
                'score' => $collectionSignalScore,
            ];
            array_push($diversityFeatures, ...$sharedCollectionSignals);
            $matchedFeaturesCount++;
            $hasStrongMatch = true;
        }

        $minScore = max(
            1,
            $minimumScore ?? (int) config('recommendations.similarity_v6.min_relevance_score', 600),
        );

        if (! $hasStrongMatch || ($metadataScore + $sourceScore) < $minScore) {
            return null;
        }

        $qualityConfig = (array) config('recommendations.similarity_v6.quality', []);
        $mediaCount = max(1, (int) $candidate['published_media_count']);
        $mediaMaximum = max(0, (int) ($qualityConfig['media'] ?? 80));
        $mediaScore = min($mediaMaximum, 50 + (int) floor(log($mediaCount + 1) * 15));
        $qualityScore += $mediaScore;
        $reasons['published_media'] = ['count' => $mediaCount, 'score' => $mediaScore];
        $rating = is_numeric($candidate['best_rating'] ?? null)
            ? min(10.0, max(0.0, (float) $candidate['best_rating']))
            : null;

        if ($rating !== null) {
            $ratingScore = (int) round(($rating / 10) * max(0, (int) ($qualityConfig['rating'] ?? 40)));
            $qualityScore += $ratingScore;
            $reasons['rating'] = ['value' => $rating, 'score' => $ratingScore];
        }

        $reviewsCount = max(0, (int) ($candidate['reviews_count'] ?? 0));

        if ($reviewsCount > 0) {
            $reviewsScore = min(
                max(0, (int) ($qualityConfig['reviews'] ?? 20)),
                (int) floor(log($reviewsCount + 1) * 8),
            );
            $qualityScore += $reviewsScore;
            $reasons['reviews'] = ['count' => $reviewsCount, 'score' => $reviewsScore];
        }

        return new CatalogRecommendationScore(
            metadataScore: $metadataScore,
            sourceScore: $sourceScore,
            qualityScore: $qualityScore,
            matchedFeaturesCount: $matchedFeaturesCount,
            reasons: $reasons,
            diversityFeatures: array_values(array_unique($diversityFeatures)),
        );
    }

    /** @return array<string, int> */
    private function weights(): array
    {
        return collect((array) config('recommendations.similarity_v6.weights', []))
            ->map(fn (mixed $weight): int => max(0, (int) $weight))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<int>
     */
    private function relationIds(array $profile, string $feature): array
    {
        $relations = is_array($profile['relations'] ?? null) ? $profile['relations'] : [];
        $ids = is_array($relations[$feature] ?? null) ? $relations[$feature] : [];
        $ids = array_values(array_unique(array_map(
            fn (mixed $id): int => (int) $id,
            array_filter($ids, fn (mixed $id): bool => is_numeric($id) && (int) $id > 0),
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function themes(array $profile): array
    {
        $themes = is_array($profile['themes'] ?? null) ? $profile['themes'] : [];
        $themes = array_values(array_unique(array_map(
            fn (string $theme): string => trim($theme),
            array_filter($themes, fn (mixed $theme): bool => is_string($theme) && trim($theme) !== ''),
        )));
        sort($themes, SORT_STRING);

        return $themes;
    }

    /**
     * @param  list<int>  $left
     * @param  list<int>  $right
     * @return array{list<int>, float, float}
     */
    private function overlap(array $left, array $right): array
    {
        $shared = array_values(array_intersect($left, $right));
        $minimum = max(1, min(count($left), count($right)));
        $union = max(1, count(array_unique([...$left, ...$right])));

        return [$shared, count($shared) / $minimum, count($shared) / $union];
    }

    private function idf(int $documentCount, int $frequency): float
    {
        return max(
            1.0,
            min(2.5, 1.0 + log10(($documentCount + 1) / (max(1, $frequency) + 1))),
        );
    }

    private function isStrongRelation(string $feature, int $sharedCount, float $overlapCoefficient): bool
    {
        return match ($feature) {
            'tag', 'director', 'network', 'studio' => $overlapCoefficient >= 0.5,
            'actor' => $sharedCount >= 2 && $overlapCoefficient >= 0.2,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $candidate
     */
    private function sharedSignalScore(array $source, array $candidate): int
    {
        $sourceSignals = is_array($source['signals'] ?? null) ? $source['signals'] : [];
        $candidateSignals = is_array($candidate['signals'] ?? null) ? $candidate['signals'] : [];
        $weight = 0;

        foreach ($sourceSignals as $key => $sourceWeight) {
            if (! is_string($key)
                || str_starts_with($key, self::EDITORIAL_COLLECTION_SIGNAL_PREFIX)
                || ! is_numeric($sourceWeight)
                || ! is_numeric($candidateSignals[$key] ?? null)) {
                continue;
            }

            $weight += min((int) $sourceWeight, (int) $candidateSignals[$key]);
        }

        return min(220, max(0, (int) floor($weight / 2)));
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array<int|string, int>>  $documentFrequency
     * @return array{int, list<string>}
     */
    private function sharedEditorialCollectionScore(
        array $source,
        array $candidate,
        array $documentFrequency,
        int $documentCount,
    ): array {
        $sourceSignals = is_array($source['signals'] ?? null) ? $source['signals'] : [];
        $candidateSignals = is_array($candidate['signals'] ?? null) ? $candidate['signals'] : [];
        $sharedKeys = [];

        foreach ($sourceSignals as $key => $sourceWeight) {
            if (! is_string($key)
                || ! str_starts_with($key, self::EDITORIAL_COLLECTION_SIGNAL_PREFIX)
                || ! is_numeric($sourceWeight)
                || (int) $sourceWeight <= 0
                || ! is_numeric($candidateSignals[$key] ?? null)
                || (int) $candidateSignals[$key] <= 0) {
                continue;
            }

            $sharedKeys[] = $key;
        }

        if ($sharedKeys === []) {
            return [0, []];
        }

        $frequencies = is_array($documentFrequency['editorial_collection'] ?? null)
            ? $documentFrequency['editorial_collection']
            : [];
        usort($sharedKeys, static function (string $left, string $right) use ($frequencies, $documentCount): int {
            $frequency = (int) ($frequencies[$left] ?? $documentCount)
                <=> (int) ($frequencies[$right] ?? $documentCount);

            return $frequency !== 0 ? $frequency : $left <=> $right;
        });
        $sharedKeys = array_slice(
            $sharedKeys,
            0,
            max(1, (int) config('recommendations.similarity_v6.collection_signal_max_shared', 3)),
        );
        $minimumSpecificity = max(
            0.0,
            min(1.0, (float) config('recommendations.similarity_v6.collection_signal_min_specificity', 0.25)),
        );
        $multiplier = max(
            0.0,
            min(1.0, (float) config('recommendations.similarity_v6.collection_signal_multiplier', 0.8)),
        );
        $score = 0;

        foreach ($sharedKeys as $key) {
            $frequency = max(1, (int) ($frequencies[$key] ?? $documentCount));
            $frequencyRatio = min(1.0, $frequency / max(1, $documentCount));
            $specificity = max($minimumSpecificity, 1.0 - sqrt($frequencyRatio));
            $score += (int) round(
                min((int) $sourceSignals[$key], (int) $candidateSignals[$key])
                * $multiplier
                * $specificity,
            );
        }

        return [
            min(
                max(1, (int) config('recommendations.similarity_v6.collection_signal_score_cap', 220)),
                max(0, $score),
            ),
            $sharedKeys,
        ];
    }

    private function yearScore(mixed $sourceYear, mixed $candidateYear): int
    {
        if (! is_numeric($sourceYear) || ! is_numeric($candidateYear)) {
            return 0;
        }

        return match (abs((int) $sourceYear - (int) $candidateYear)) {
            0 => 90,
            1 => 45,
            2 => 25,
            default => 0,
        };
    }
}
