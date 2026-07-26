<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationPreferenceData;
use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Enums\CatalogRecommendationPlaybackPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogRecommendationPreferenceQuery
{
    /** @var array<int, CatalogRecommendationPreferenceData> */
    private array $resolved = [];

    public function __construct(
        private readonly CatalogRecommendationPreferenceSchema $schema,
        private readonly CatalogTasteOnboardingSchema $onboardingSchema,
    ) {}

    public function forUser(User $user): CatalogRecommendationPreferenceData
    {
        if (isset($this->resolved[$user->id])) {
            return $this->resolved[$user->id];
        }

        if (! $this->schema->ready()) {
            return CatalogRecommendationPreferenceData::defaults();
        }

        $onboardingReady = $this->onboardingSchema->ready();
        $columns = [
            'recommendation_preferences.diversity',
            'recommendation_preferences.freshness',
            'recommendation_preferences.profile_reset_at',
            'hidden_genres.genre_id',
        ];

        if ($onboardingReady) {
            $columns = [
                ...$columns,
                'recommendation_preferences.playback_preference',
                'recommendation_preferences.completion_preference',
                'recommendation_preferences.episode_length_preference',
                'recommendation_preferences.onboarding_completed_at',
            ];
        }

        $rows = DB::table('users')
            ->leftJoin(
                'catalog_recommendation_preferences as recommendation_preferences',
                'recommendation_preferences.user_id',
                '=',
                'users.id',
            )
            ->leftJoin('catalog_recommendation_hidden_genres as hidden_genres', function ($join): void {
                $join
                    ->on('hidden_genres.user_id', '=', 'users.id')
                    ->where('hidden_genres.hidden_until', '>', now());
            })
            ->where('users.id', $user->id)
            ->get($columns);
        $row = $rows->first();

        if ($row === null) {
            return $this->resolved[$user->id] = CatalogRecommendationPreferenceData::defaults();
        }

        $diversity = CatalogRecommendationDiversityPreference::tryFrom((string) ($row->diversity ?? ''));
        $freshness = CatalogRecommendationFreshnessPreference::tryFrom((string) ($row->freshness ?? ''));
        $resetAt = is_string($row->profile_reset_at) && $row->profile_reset_at !== ''
            ? CarbonImmutable::parse($row->profile_reset_at)
            : null;
        $hiddenGenreIds = $rows
            ->pluck('genre_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $selections = $onboardingReady ? $this->onboardingSelections($user) : collect();
        $onboardingCompletedAt = $onboardingReady
            && is_string($row->onboarding_completed_at)
            && $row->onboarding_completed_at !== ''
                ? CarbonImmutable::parse($row->onboarding_completed_at)
                : null;

        return $this->resolved[$user->id] = new CatalogRecommendationPreferenceData(
            diversity: $diversity ?? CatalogRecommendationDiversityPreference::Balanced,
            freshness: $freshness ?? CatalogRecommendationFreshnessPreference::Balanced,
            profileResetAt: $resetAt,
            hiddenGenreIds: $hiddenGenreIds,
            playbackPreference: CatalogRecommendationPlaybackPreference::tryFrom(
                (string) ($row->playback_preference ?? ''),
            ) ?? CatalogRecommendationPlaybackPreference::Any,
            completionPreference: CatalogRecommendationCompletionPreference::tryFrom(
                (string) ($row->completion_preference ?? ''),
            ) ?? CatalogRecommendationCompletionPreference::Any,
            episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::tryFrom(
                (string) ($row->episode_length_preference ?? ''),
            ) ?? CatalogRecommendationEpisodeLengthPreference::Any,
            onboardingCompletedAt: $onboardingCompletedAt,
            preferredGenreIds: $this->selectionIds($selections, 'genre'),
            preferredCountryIds: $this->selectionIds($selections, 'country'),
            likedTitleIds: $this->selectionIds($selections, 'liked'),
            excludedTitleIds: $this->selectionIds($selections, 'excluded'),
        );
    }

    public function forget(User $user): void
    {
        unset($this->resolved[$user->id]);
    }

    /** @return Collection<int, object> */
    private function onboardingSelections(User $user): Collection
    {
        $query = DB::table('catalog_recommendation_onboarding_titles')
            ->where('user_id', $user->id)
            ->select(['kind as bucket', 'catalog_title_id as value']);

        $query->unionAll(
            DB::table('catalog_recommendation_preferred_genres')
                ->where('user_id', $user->id)
                ->selectRaw("'genre' AS bucket, genre_id AS value"),
        );
        $query->unionAll(
            DB::table('catalog_recommendation_preferred_countries')
                ->where('user_id', $user->id)
                ->selectRaw("'country' AS bucket, country_id AS value"),
        );

        return $query->get();
    }

    /**
     * @param  Collection<int, object>  $selections
     * @return list<int>
     */
    private function selectionIds(Collection $selections, string $bucket): array
    {
        return $selections
            ->where('bucket', $bucket)
            ->pluck('value')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
