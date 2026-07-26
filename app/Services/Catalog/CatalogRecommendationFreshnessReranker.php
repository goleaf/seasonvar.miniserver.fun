<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Models\CatalogTitle;

final class CatalogRecommendationFreshnessReranker
{
    /**
     * @param  list<array{id: int, score: int}>  $candidates
     * @return list<array{id: int, score: int}>
     */
    public function rerank(
        array $candidates,
        CatalogRecommendationFreshnessPreference $preference,
    ): array {
        if ($candidates === [] || $preference === CatalogRecommendationFreshnessPreference::Balanced) {
            return $candidates;
        }

        $years = CatalogTitle::query()
            ->whereKey(array_column($candidates, 'id'))
            ->pluck('year', 'id');
        $cap = max(1, min(100, (int) config('recommendations.feedback.freshness_adjustment_cap', 40)));
        $newBoundary = now()->year - max(0, (int) config('recommendations.feedback.new_title_years', 2));
        $provenBoundary = now()->year - max(1, (int) config('recommendations.feedback.proven_title_years', 5));

        foreach ($candidates as &$candidate) {
            $year = $years->get((int) $candidate['id']);

            if (! is_numeric($year)) {
                continue;
            }

            $year = (int) $year;
            $adjustment = $preference === CatalogRecommendationFreshnessPreference::Newer
                ? ($year >= $newBoundary ? $cap : -$cap)
                : ($year <= $provenBoundary ? $cap : -$cap);
            $candidate['score'] += $adjustment;
        }
        unset($candidate);

        usort(
            $candidates,
            static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
                ?: ($right['id'] <=> $left['id']),
        );

        return $candidates;
    }
}
