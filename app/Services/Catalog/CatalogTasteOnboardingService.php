<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogTasteOnboardingData;
use App\DTOs\CatalogTasteOnboardingState;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Enums\CatalogRecommendationOnboardingTitleKind;
use App\Enums\PlaybackPreferenceMode;
use App\Models\CatalogRecommendationPreference;
use App\Models\Country;
use App\Models\Genre;
use App\Models\User;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class CatalogTasteOnboardingService
{
    private const LIKED_MINIMUM = 5;

    private const LIKED_MAXIMUM = 10;

    private const EXCLUDED_MAXIMUM = 10;

    private const TAXONOMY_MAXIMUM = 8;

    public function __construct(
        private CatalogTasteOnboardingSchema $schema,
        private CatalogTitleQuery $titles,
        private CatalogTasteOnboardingQuery $query,
        private CatalogRecommendationPreferenceQuery $preferences,
        private CatalogRecommendationRepeatSuppressor $repeats,
        private AccountSettingsService $accountSettings,
    ) {}

    public function save(User $user, CatalogTasteOnboardingData $data): CatalogTasteOnboardingState
    {
        $this->authorize($user);
        $this->hitRateLimit($user);
        $likedTitleIds = $this->validateIds(
            'likedTitleIds',
            $data->likedTitleIds,
            self::LIKED_MINIMUM,
            self::LIKED_MAXIMUM,
        );
        $excludedTitleIds = $this->validateIds(
            'excludedTitleIds',
            $data->excludedTitleIds,
            0,
            self::EXCLUDED_MAXIMUM,
        );
        $genreIds = $this->validateIds('genreIds', $data->genreIds, 1, self::TAXONOMY_MAXIMUM);
        $countryIds = $this->validateIds('countryIds', $data->countryIds, 1, self::TAXONOMY_MAXIMUM);

        if (array_intersect($likedTitleIds, $excludedTitleIds) !== []) {
            throw ValidationException::withMessages([
                'excludedTitleIds' => __('onboarding.validation.title_overlap'),
            ]);
        }

        $this->validateLocale($data->locale);
        $this->validateVisibleTitles($user, [...$likedTitleIds, ...$excludedTitleIds], $likedTitleIds);
        $this->validateTaxonomyIds(Genre::query(), 'genreIds', $genreIds);
        $this->validateTaxonomyIds(Country::query(), 'countryIds', $countryIds);

        DB::transaction(function () use (
            $countryIds,
            $data,
            $excludedTitleIds,
            $genreIds,
            $likedTitleIds,
            $user,
        ): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $preference = CatalogRecommendationPreference::query()->lockForUpdate()->find($user->id)
                ?? new CatalogRecommendationPreference([
                    'user_id' => $user->id,
                    'diversity' => CatalogRecommendationDiversityPreference::Balanced,
                    'freshness' => CatalogRecommendationFreshnessPreference::Balanced,
                    'version' => 1,
                ]);
            $preference->forceFill([
                'playback_preference' => $data->playbackPreference,
                'completion_preference' => $data->completionPreference,
                'episode_length_preference' => $data->episodeLengthPreference,
                'onboarding_completed_at' => now(),
                'version' => max(1, (int) $preference->version) + 1,
            ])->save();

            $this->replaceTitleRows($user, $likedTitleIds, $excludedTitleIds);
            $this->replaceRows(
                'catalog_recommendation_preferred_genres',
                'genre_id',
                $user,
                $genreIds,
            );
            $this->replaceRows(
                'catalog_recommendation_preferred_countries',
                'country_id',
                $user,
                $countryIds,
            );
            $this->accountSettings->updateOnboardingPreferences(
                $user,
                $data->locale,
                match ($data->playbackPreference->value) {
                    'subtitles' => true,
                    'dubbed' => false,
                    default => null,
                },
                match ($data->playbackPreference->value) {
                    'subtitles' => PlaybackPreferenceMode::OriginalSubtitles,
                    'dubbed' => PlaybackPreferenceMode::Dubbed,
                    default => PlaybackPreferenceMode::Automatic,
                },
            );
        }, attempts: 3);

        $this->preferences->forget($user);
        $this->repeats->forget($user);

        return $this->query->state($user);
    }

    private function authorize(User $user): void
    {
        Gate::forUser($user)->authorize('update-account-settings');
        abort_unless($user->hasVerifiedEmail(), 403);
        abort_unless($this->schema->ready(), 503, __('onboarding.errors.unavailable'));
    }

    private function hitRateLimit(User $user): void
    {
        $key = 'taste-onboarding:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 12)) {
            throw ValidationException::withMessages([
                'onboarding' => __('onboarding.validation.rate_limited'),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<int>
     */
    private function validateIds(string $field, array $ids, int $minimum, int $maximum): array
    {
        $normalized = collect($ids)
            ->map(static fn (mixed $id): int => is_int($id)
                || (is_string($id) && ctype_digit($id))
                    ? (int) $id
                    : 0)
            ->values();

        if (! array_is_list($ids)
            || $normalized->contains(static fn (int $id): bool => $id <= 0)
            || $normalized->unique()->count() !== $normalized->count()
            || $normalized->count() < $minimum
            || $normalized->count() > $maximum) {
            throw ValidationException::withMessages([
                $field => __('onboarding.validation.'.$field),
            ]);
        }

        return $normalized->all();
    }

    private function validateLocale(string $locale): void
    {
        if (! in_array($locale, (array) config('catalog-collections.supported_locales', []), true)) {
            throw ValidationException::withMessages([
                'locale' => __('onboarding.validation.locale'),
            ]);
        }
    }

    /**
     * @param  list<int>  $titleIds
     * @param  list<int>  $likedTitleIds
     */
    private function validateVisibleTitles(User $user, array $titleIds, array $likedTitleIds): void
    {
        $resolved = $this->titles->visibleTo($user)
            ->whereKey($titleIds)
            ->pluck('catalog_titles.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $unknown = array_diff($titleIds, $resolved);

        if ($unknown === []) {
            return;
        }

        $field = array_intersect($unknown, $likedTitleIds) === [] ? 'excludedTitleIds' : 'likedTitleIds';

        throw ValidationException::withMessages([
            $field => __('onboarding.validation.titles_unavailable'),
        ]);
    }

    /**
     * @param  Builder<Genre|Country>  $query
     * @param  list<int>  $ids
     */
    private function validateTaxonomyIds(
        Builder $query,
        string $field,
        array $ids,
    ): void {
        if ((clone $query)->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([
                $field => __('onboarding.validation.taxonomy_unavailable'),
            ]);
        }
    }

    /** @param list<int> $likedTitleIds
     * @param  list<int>  $excludedTitleIds
     */
    private function replaceTitleRows(User $user, array $likedTitleIds, array $excludedTitleIds): void
    {
        DB::table('catalog_recommendation_onboarding_titles')
            ->where('user_id', $user->id)
            ->delete();
        $now = now();
        $rows = collect([
            CatalogRecommendationOnboardingTitleKind::Liked->value => $likedTitleIds,
            CatalogRecommendationOnboardingTitleKind::Excluded->value => $excludedTitleIds,
        ])->flatMap(fn (array $ids, string $kind): array => collect($ids)
            ->map(fn (int $titleId): array => [
                'user_id' => $user->id,
                'catalog_title_id' => $titleId,
                'kind' => $kind,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all())
            ->all();

        if ($rows !== []) {
            DB::table('catalog_recommendation_onboarding_titles')->insert($rows);
        }
    }

    /** @param list<int> $ids */
    private function replaceRows(string $table, string $column, User $user, array $ids): void
    {
        DB::table($table)->where('user_id', $user->id)->delete();
        $now = now();
        DB::table($table)->insert(collect($ids)
            ->map(fn (int $id): array => [
                'user_id' => $user->id,
                $column => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all());
    }
}
