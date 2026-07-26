<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationPreferenceData;
use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationPlaybackPreference;
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
        $candidateIds = collect($candidates)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $demotions = $pendingIds === [] ? [] : $this->negativePreferences->forUser($user);
        $hasPositivePreferences = $this->hasPositivePreferences($preferences);
        $featuresByTitle = $demotions === [] && ! $hasPositivePreferences
            ? []
            : $this->features->forTitleIds($candidateIds);
        $totalCap = max(0, (int) config('recommendations.personalized_v2.negative_total_cap', 240));

        foreach ($candidates as &$candidate) {
            if (($candidate['taste_demotions_applied'] ?? false) !== true) {
                $demotion = collect($featuresByTitle[(int) ($candidate['id'] ?? 0)] ?? [])
                    ->sum(static fn (string $feature): int => (int) ($demotions[$feature] ?? 0));
                $candidate['score'] = max(0, (int) ($candidate['score'] ?? 0) - min($totalCap, $demotion));
            }

            $candidate['score'] = (int) ($candidate['score'] ?? 0)
                + $this->positiveBonus(
                    $featuresByTitle[(int) ($candidate['id'] ?? 0)] ?? [],
                    $preferences,
                );
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

    private function hasPositivePreferences(CatalogRecommendationPreferenceData $preferences): bool
    {
        return $preferences->preferredGenreIds !== []
            || $preferences->preferredCountryIds !== []
            || $preferences->playbackPreference !== CatalogRecommendationPlaybackPreference::Any
            || $preferences->completionPreference !== CatalogRecommendationCompletionPreference::Any
            || $preferences->episodeLengthPreference !== CatalogRecommendationEpisodeLengthPreference::Any;
    }

    /**
     * @param  list<string>  $features
     */
    private function positiveBonus(
        array $features,
        CatalogRecommendationPreferenceData $preferences,
    ): int {
        if ($features === [] || ! $this->hasPositivePreferences($preferences)) {
            return 0;
        }

        $genreBonus = collect($preferences->preferredGenreIds)
            ->filter(fn (int $id): bool => in_array('genre:'.$id, $features, true))
            ->count() * max(0, (int) config('recommendations.onboarding.genre_weight', 35));
        $genreBonus = min(
            max(0, (int) config('recommendations.onboarding.genre_cap', 70)),
            $genreBonus,
        );
        $countryBonus = collect($preferences->preferredCountryIds)
            ->contains(fn (int $id): bool => in_array('country:'.$id, $features, true))
                ? max(0, (int) config('recommendations.onboarding.country_weight', 35))
                : 0;
        $playbackFeature = match ($preferences->playbackPreference) {
            CatalogRecommendationPlaybackPreference::Dubbed => 'availability:dubbed',
            CatalogRecommendationPlaybackPreference::Subtitles => 'availability:subtitles',
            CatalogRecommendationPlaybackPreference::Any => null,
        };
        $completionFeature = match ($preferences->completionPreference) {
            CatalogRecommendationCompletionPreference::Completed => 'status:completed',
            CatalogRecommendationCompletionPreference::Ongoing => 'status:unfinished',
            CatalogRecommendationCompletionPreference::Any => null,
        };
        $durationFeature = match ($preferences->episodeLengthPreference) {
            CatalogRecommendationEpisodeLengthPreference::Short => 'duration:short',
            CatalogRecommendationEpisodeLengthPreference::Long => 'duration:long',
            CatalogRecommendationEpisodeLengthPreference::Any => null,
        };
        $bonus = $genreBonus + $countryBonus;
        $bonus += $playbackFeature !== null && in_array($playbackFeature, $features, true)
            ? max(0, (int) config('recommendations.onboarding.playback_weight', 30))
            : 0;
        $bonus += $completionFeature !== null && in_array($completionFeature, $features, true)
            ? max(0, (int) config('recommendations.onboarding.completion_weight', 25))
            : 0;
        $bonus += $durationFeature !== null && in_array($durationFeature, $features, true)
            ? max(0, (int) config('recommendations.onboarding.episode_length_weight', 20))
            : 0;

        return min(
            max(0, (int) config('recommendations.onboarding.positive_total_cap', 140)),
            $bonus,
        );
    }
}
