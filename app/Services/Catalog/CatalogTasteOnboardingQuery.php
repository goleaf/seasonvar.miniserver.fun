<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogTasteOnboardingState;
use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationOnboardingTitleKind;
use App\Enums\CatalogRecommendationPlaybackPreference;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogTitleSuggestionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CatalogTasteOnboardingQuery
{
    public function __construct(
        private CatalogTasteOnboardingSchema $schema,
        private CatalogTitleQuery $titles,
        private CatalogSearchQueryParser $searchParser,
        private CatalogTitleSuggestionQuery $suggestions,
    ) {}

    public function state(User $user): CatalogTasteOnboardingState
    {
        if (! $this->schema->ready()) {
            return CatalogTasteOnboardingState::defaults();
        }

        $preference = DB::table('catalog_recommendation_preferences')
            ->where('user_id', $user->id)
            ->first([
                'playback_preference',
                'completion_preference',
                'episode_length_preference',
                'onboarding_completed_at',
            ]);
        $titles = DB::table('catalog_recommendation_onboarding_titles')
            ->where('user_id', $user->id)
            ->orderBy('catalog_title_id')
            ->get(['catalog_title_id', 'kind']);

        return new CatalogTasteOnboardingState(
            likedTitleIds: $titles
                ->where('kind', CatalogRecommendationOnboardingTitleKind::Liked->value)
                ->pluck('catalog_title_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
            excludedTitleIds: $titles
                ->where('kind', CatalogRecommendationOnboardingTitleKind::Excluded->value)
                ->pluck('catalog_title_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
            genreIds: $this->ownerIds('catalog_recommendation_preferred_genres', 'genre_id', $user),
            countryIds: $this->ownerIds('catalog_recommendation_preferred_countries', 'country_id', $user),
            playbackPreference: CatalogRecommendationPlaybackPreference::tryFrom(
                (string) ($preference->playback_preference ?? ''),
            ) ?? CatalogRecommendationPlaybackPreference::Any,
            completionPreference: CatalogRecommendationCompletionPreference::tryFrom(
                (string) ($preference->completion_preference ?? ''),
            ) ?? CatalogRecommendationCompletionPreference::Any,
            episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::tryFrom(
                (string) ($preference->episode_length_preference ?? ''),
            ) ?? CatalogRecommendationEpisodeLengthPreference::Any,
            completedAt: is_string($preference?->onboarding_completed_at)
                && $preference->onboarding_completed_at !== ''
                    ? CarbonImmutable::parse($preference->onboarding_completed_at)
                    : null,
        );
    }

    /** @return list<array{id: int, name: string}> */
    public function genres(): array
    {
        return Genre::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Genre $genre): array => ['id' => $genre->id, 'name' => $genre->name])
            ->all();
    }

    /** @return list<array{id: int, name: string}> */
    public function countries(): array
    {
        return Country::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Country $country): array => ['id' => $country->id, 'name' => $country->name])
            ->all();
    }

    /** @return list<array{id: int, title: string, year: int|null, poster_url: string|null}> */
    public function searchTitles(User $user, string $search, int $limit = 12): array
    {
        return $this->suggestions
            ->search($this->searchParser->parse($search), $user, $limit)
            ->map(fn (CatalogTitle $title): array => $this->titleRow($title))
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, title: string, year: int|null, poster_url: string|null}>
     */
    public function selectedTitles(User $user, array $ids): array
    {
        $ids = collect($ids)
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(20)
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $order = array_flip($ids);

        return $this->titles->visibleTo($user)
            ->whereKey($ids)
            ->get(['id', 'title', 'original_title', 'year', 'poster_url'])
            ->sortBy(static fn (CatalogTitle $title): int => $order[$title->id])
            ->map(fn (CatalogTitle $title): array => $this->titleRow($title))
            ->values()
            ->all();
    }

    public function resolveVisibleTitle(User $user, int $titleId): ?CatalogTitle
    {
        return $this->titles->visibleTo($user)
            ->whereKey($titleId)
            ->first(['id', 'title', 'original_title', 'year', 'poster_url']);
    }

    /** @return list<int> */
    private function ownerIds(string $table, string $column, User $user): array
    {
        return DB::table($table)
            ->where('user_id', $user->id)
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return array{id: int, title: string, year: int|null, poster_url: string|null} */
    private function titleRow(CatalogTitle $title): array
    {
        return [
            'id' => $title->id,
            'title' => $title->display_title,
            'year' => $title->year,
            'poster_url' => is_string($title->poster_url) ? $title->poster_url : null,
        ];
    }
}
