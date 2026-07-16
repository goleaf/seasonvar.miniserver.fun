<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationType;
use App\Models\LicensedMedia;
use App\Services\Auth\AccountSettingsService;

final class CatalogRecommendationAvailabilityReranker
{
    public function __construct(private readonly AccountSettingsService $settings) {}

    /**
     * @param list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}> $candidates
     * @return list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>
     */
    public function rerank(CatalogRecommendationContext $context, array $candidates): array
    {
        if ($context->user === null || $context->type !== CatalogRecommendationType::Personalized || $candidates === []) {
            return $candidates;
        }

        $preferences = $this->settings->resolve($context->user);
        $candidateIds = array_column($candidates, 'id');
        $boosts = [];

        if ($preferences->preferredQuality !== null) {
            $this->matchingTitleIds($context, $candidateIds, 'quality', $preferences->preferredQuality)
                ->each(function (mixed $id) use (&$boosts): void {
                    $id = (int) $id;
                    $boosts[$id] = ($boosts[$id] ?? 0) + (int) config('recommendations.availability_boosts.quality', 12);
                });
        }

        if ($preferences->preferredVariant !== null) {
            $this->matchingTitleIds($context, $candidateIds, 'variant_key', $preferences->preferredVariant)
                ->each(function (mixed $id) use (&$boosts): void {
                    $id = (int) $id;
                    $boosts[$id] = ($boosts[$id] ?? 0) + (int) config('recommendations.availability_boosts.variant', 12);
                });
        }

        if ($preferences->subtitlesEnabled) {
            $this->matchingTitleIds($context, $candidateIds, 'has_subtitles', true)
                ->each(function (mixed $id) use (&$boosts): void {
                    $id = (int) $id;
                    $boosts[$id] = ($boosts[$id] ?? 0) + (int) config('recommendations.availability_boosts.subtitles', 6);
                });
        }

        foreach ($candidates as &$candidate) {
            $candidate['score'] += max(0, (int) ($boosts[$candidate['id']] ?? 0));
        }
        unset($candidate);

        usort($candidates, fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($right['id'] <=> $left['id']));

        return $candidates;
    }

    /** @param list<int> $candidateIds */
    private function matchingTitleIds(
        CatalogRecommendationContext $context,
        array $candidateIds,
        string $column,
        string|bool $value,
    ): \Illuminate\Support\Collection {
        return LicensedMedia::query()
            ->availableTo($context->user)
            ->forAvailableReleases($context->user)
            ->withoutKnownFailures()
            ->whereIn('catalog_title_id', $candidateIds)
            ->where($column, $value)
            ->distinct()
            ->pluck('catalog_title_id');
    }
}
