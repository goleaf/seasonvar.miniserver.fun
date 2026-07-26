<?php

declare(strict_types=1);

namespace App\Services\Collections\Quality;

use App\DTOs\CollectionQuality\CatalogCollectionQualityFacts;
use App\DTOs\CollectionQuality\CatalogCollectionQualityIssueData;
use App\DTOs\CollectionQuality\CatalogCollectionQualityResult;
use App\Enums\CatalogCollectionQualityIssueSeverity;
use Illuminate\Support\Str;

final class CatalogCollectionQualityEvaluator
{
    /**
     * @var array{
     *     maximum_public_items: int,
     *     minimum_public_score: int,
     *     template_repetition_threshold: int
     * }
     */
    private readonly array $settings;

    /** @param array<string, int>|null $settings */
    public function __construct(?array $settings = null)
    {
        $this->settings = [
            'maximum_public_items' => max(
                1,
                (int) ($settings['maximum_public_items']
                    ?? config('catalog-collections.maximum_public_items_per_collection', 500)),
            ),
            'minimum_public_score' => min(
                100,
                max(
                    0,
                    (int) ($settings['minimum_public_score']
                        ?? config('catalog-collections.quality.minimum_public_score', 60)),
                ),
            ),
            'template_repetition_threshold' => max(
                2,
                (int) ($settings['template_repetition_threshold']
                    ?? ($settings === null
                        ? config(
                            'catalog-collections.quality.template_repetition_threshold',
                            3,
                        )
                        : 3)),
            ),
        ];
    }

    public function evaluate(CatalogCollectionQualityFacts $facts): CatalogCollectionQualityResult
    {
        $issues = $this->issues($facts);
        $components = [
            'metadata' => $this->metadataScore($facts),
            'structure' => $this->structureScore($facts),
            'theme' => $this->themeScore($facts),
            'trust' => $this->trustScore($facts),
        ];
        $duplicatePenalty = $facts->exactDuplicateCollectionId !== null ? 35 : 0;
        $similarityPenalty = $facts->similarTextCollectionId !== null ? 10 : 0;
        $templatePenalty = $facts->repeatedTextCount
            >= $this->settings['template_repetition_threshold'] ? 15 : 0;
        $score = min(100, max(
            0,
            array_sum($components) - $duplicatePenalty - $similarityPenalty - $templatePenalty,
        ));

        return new CatalogCollectionQualityResult(
            score: $score,
            components: $components,
            issues: $issues,
            details: [
                'threshold' => $this->settings['minimum_public_score'],
                'engagement' => [
                    'saves' => $facts->saveCount,
                    'completions' => $facts->completionCount,
                    'returns' => $facts->returnCount,
                    'reports' => $facts->reportCount,
                ],
                'penalties' => [
                    'exact_duplicate' => $duplicatePenalty,
                    'similar_text' => $similarityPenalty,
                    'template_content' => $templatePenalty,
                ],
            ],
        );
    }

    /** @return list<CatalogCollectionQualityIssueData> */
    private function issues(CatalogCollectionQualityFacts $facts): array
    {
        $issues = [];

        if (! $facts->categoryPresent || ! $facts->categoryActive) {
            $issues[] = $this->issue(
                'missing_category',
                CatalogCollectionQualityIssueSeverity::Critical,
            );
        }

        if ($facts->itemCount === 0) {
            $issues[] = $this->issue(
                'empty_collection',
                CatalogCollectionQualityIssueSeverity::Critical,
            );
        } elseif ($facts->itemCount > $this->settings['maximum_public_items']) {
            $issues[] = $this->issue(
                'too_many_items',
                CatalogCollectionQualityIssueSeverity::Critical,
                ['count' => $facts->itemCount],
            );
        }

        if ($facts->itemCount > 0 && (
            $facts->averageThemeMatch < 40
            || $facts->preciseReasonCount / $facts->itemCount < 0.50
        )) {
            $issues[] = $this->issue(
                'weak_theme',
                CatalogCollectionQualityIssueSeverity::Warning,
                ['average_match' => $facts->averageThemeMatch],
            );
        }

        if ($facts->exactDuplicateCollectionId !== null) {
            $issues[] = $this->issue(
                'exact_duplicate',
                CatalogCollectionQualityIssueSeverity::Critical,
                ['related_collection_id' => $facts->exactDuplicateCollectionId],
            );
        }

        if ($facts->similarTextCollectionId !== null) {
            $issues[] = $this->issue(
                'similar_text',
                CatalogCollectionQualityIssueSeverity::Warning,
                ['related_collection_id' => $facts->similarTextCollectionId],
            );
        }

        if ($facts->repeatedTextCount >= $this->settings['template_repetition_threshold']) {
            $issues[] = $this->issue(
                'template_content',
                CatalogCollectionQualityIssueSeverity::Warning,
                ['repetitions' => $facts->repeatedTextCount],
            );
        }

        if ($facts->reportCount > 0) {
            $issues[] = $this->issue(
                'user_reports',
                CatalogCollectionQualityIssueSeverity::Warning,
                ['count' => $facts->reportCount],
            );
        }

        return $issues;
    }

    private function metadataScore(CatalogCollectionQualityFacts $facts): int
    {
        $score = 0;
        $score += $facts->categoryPresent && $facts->categoryActive ? 10 : 0;
        $score += $this->meaningful($facts->name, 3) ? 5 : 0;
        $score += $this->meaningful($facts->description, 40) ? 5 : 0;
        $score += $facts->repeatedTextCount
            < $this->settings['template_repetition_threshold'] ? 5 : 0;

        return $score;
    }

    private function structureScore(CatalogCollectionQualityFacts $facts): int
    {
        if ($facts->itemCount === 0) {
            return 0;
        }

        $score = $facts->itemCount <= $this->settings['maximum_public_items'] ? 15 : 0;
        $watchableRatio = min(1.0, $facts->watchableItemCount / $facts->itemCount);

        return $score + (int) round($watchableRatio * 10);
    }

    private function themeScore(CatalogCollectionQualityFacts $facts): int
    {
        if ($facts->itemCount === 0) {
            return 0;
        }

        $coverage = min(1.0, $facts->preciseReasonCount / $facts->itemCount);

        return (int) round($facts->averageThemeMatch * 0.20)
            + (int) round($coverage * 10);
    }

    private function trustScore(CatalogCollectionQualityFacts $facts): int
    {
        $reportScore = max(0, 5 - min(5, $facts->reportCount));
        $engagementScore = min(4, $facts->saveCount)
            + min(3, $facts->completionCount)
            + min(3, $facts->returnCount);
        $editorialScore = $facts->editoriallyVerifiedCurrent ? 5 : 0;

        return min(20, $reportScore + $engagementScore + $editorialScore);
    }

    private function meaningful(?string $value, int $minimumLength): bool
    {
        return $value !== null
            && Str::length(Str::squish($value)) >= $minimumLength
            && preg_match('/[\p{L}\p{N}]/u', $value) === 1;
    }

    /** @param array<string, int|float|string|bool|null> $evidence */
    private function issue(
        string $code,
        CatalogCollectionQualityIssueSeverity $severity,
        array $evidence = [],
    ): CatalogCollectionQualityIssueData {
        return new CatalogCollectionQualityIssueData($code, $severity, $evidence);
    }
}
