<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationPreferenceData;
use App\Models\User;

final class CatalogRecommendationTasteReranker
{
    public function __construct(
        private readonly CatalogPersonalNegativePreferenceBuilder $negativePreferences,
        private readonly CatalogRecommendationFeatureExtractor $features,
        private readonly CatalogRecommendationFreshnessReranker $freshness,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    public function rerank(
        User $user,
        array $candidates,
        CatalogRecommendationPreferenceData $preferences,
    ): array {
        if ($candidates === []) {
            return [];
        }

        $blendSlots = $this->blendSlots($candidates);
        $pendingIds = collect($candidates)
            ->reject(static fn (array $candidate): bool => ($candidate['taste_demotions_applied'] ?? false) === true)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $demotions = $pendingIds === [] ? [] : $this->negativePreferences->forUser($user);
        $featuresByTitle = $demotions === [] ? [] : $this->features->forTitleIds($pendingIds);
        $totalCap = max(0, (int) config('recommendations.personalized_v2.negative_total_cap', 240));

        foreach ($candidates as &$candidate) {
            if (($candidate['taste_demotions_applied'] ?? false) !== true) {
                $demotion = collect($featuresByTitle[(int) ($candidate['id'] ?? 0)] ?? [])
                    ->sum(static fn (string $feature): int => (int) ($demotions[$feature] ?? 0));
                $candidate['score'] = max(0, (int) ($candidate['score'] ?? 0) - min($totalCap, $demotion));
            }

            unset($candidate['taste_demotions_applied']);
        }
        unset($candidate);

        usort(
            $candidates,
            static fn (array $left, array $right): int => ((int) $right['score'] <=> (int) $left['score'])
                ?: ((int) $right['id'] <=> (int) $left['id']),
        );

        $candidates = $this->freshness->rerank($candidates, $preferences->freshness);

        return $blendSlots === null
            ? $candidates
            : $this->fillBlendSlots($blendSlots, $candidates);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array{personal: bool, position: int}>|null
     */
    private function blendSlots(array $candidates): ?array
    {
        if (! collect($candidates)->contains(
            static fn (array $candidate): bool => is_int($candidate['blend_position'] ?? null),
        )) {
            return null;
        }

        return array_map(
            fn (array $candidate): array => [
                'personal' => $this->isPersonal($candidate),
                'position' => is_int($candidate['blend_position'] ?? null)
                    ? $candidate['blend_position']
                    : PHP_INT_MAX,
            ],
            $candidates,
        );
    }

    /**
     * @param  list<array{personal: bool, position: int}>  $slots
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function fillBlendSlots(array $slots, array $candidates): array
    {
        $personal = array_values(array_filter($candidates, $this->isPersonal(...)));
        $public = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => ! $this->isPersonal($candidate),
        ));
        $personalIndex = 0;
        $publicIndex = 0;
        $blended = [];

        foreach ($slots as $slot) {
            $candidate = $slot['personal']
                ? ($personal[$personalIndex++] ?? $public[$publicIndex++] ?? null)
                : ($public[$publicIndex++] ?? $personal[$personalIndex++] ?? null);

            if ($candidate === null) {
                break;
            }

            $candidate['blend_position'] = $slot['position'];
            $blended[] = $candidate;
        }

        return $blended;
    }

    /** @param array<string, mixed> $candidate */
    private function isPersonal(array $candidate): bool
    {
        return str_starts_with((string) ($candidate['source'] ?? ''), 'user_');
    }
}
