<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationPreferenceData;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogRecommendationHiddenGenre;
use App\Models\CatalogRecommendationPreference;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class CatalogRecommendationPreferenceService
{
    public function __construct(
        private readonly CatalogRecommendationPreferenceSchema $schema,
        private readonly CatalogRecommendationPreferenceQuery $query,
        private readonly CatalogRecommendationRepeatSuppressor $repeats,
    ) {}

    public function update(
        User $user,
        CatalogRecommendationDiversityPreference $diversity,
        CatalogRecommendationFreshnessPreference $freshness,
    ): CatalogRecommendationPreferenceData {
        $this->authorize($user);
        $this->hitRateLimit($user);

        DB::transaction(function () use ($diversity, $freshness, $user): void {
            $preference = $this->lockedPreference($user);
            $preference->forceFill([
                'diversity' => $diversity,
                'freshness' => $freshness,
            ]);
            $this->saveIfChanged($preference);
        }, attempts: 3);

        $this->query->forget($user);

        return $this->query->forUser($user);
    }

    public function hideGenre(User $user, Genre $genre): CatalogRecommendationPreferenceData
    {
        $this->authorize($user);
        $this->hitRateLimit($user);
        $days = max(1, min(365, (int) config('recommendations.feedback.hidden_genre_days', 30)));

        CatalogRecommendationHiddenGenre::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'genre_id' => $genre->id,
            ],
            ['hidden_until' => now()->addDays($days)],
        );

        $this->query->forget($user);

        return $this->query->forUser($user);
    }

    public function restoreGenre(User $user, Genre $genre): CatalogRecommendationPreferenceData
    {
        $this->authorize($user);
        $this->hitRateLimit($user);
        CatalogRecommendationHiddenGenre::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($genre)
            ->delete();

        $this->query->forget($user);

        return $this->query->forUser($user);
    }

    public function reset(User $user): CatalogRecommendationPreferenceData
    {
        $this->authorize($user);
        $this->hitRateLimit($user);

        DB::transaction(function () use ($user): void {
            $preference = $this->lockedPreference($user);
            $preference->forceFill([
                'diversity' => CatalogRecommendationDiversityPreference::Balanced,
                'freshness' => CatalogRecommendationFreshnessPreference::Balanced,
                'profile_reset_at' => now(),
            ]);
            $this->saveIfChanged($preference);
            CatalogRecommendationFeedbackDetail::query()->whereBelongsTo($user)->delete();
            CatalogRecommendationHiddenGenre::query()->whereBelongsTo($user)->delete();
        }, attempts: 3);

        $this->repeats->forget($user);
        $this->query->forget($user);

        return $this->query->forUser($user);
    }

    private function lockedPreference(User $user): CatalogRecommendationPreference
    {
        User::query()->lockForUpdate()->findOrFail($user->id);

        return CatalogRecommendationPreference::query()->lockForUpdate()->find($user->id)
            ?? new CatalogRecommendationPreference([
                'user_id' => $user->id,
                'diversity' => CatalogRecommendationDiversityPreference::Balanced,
                'freshness' => CatalogRecommendationFreshnessPreference::Balanced,
                'version' => 1,
            ]);
    }

    private function saveIfChanged(CatalogRecommendationPreference $preference): void
    {
        if (! $preference->exists || $preference->isDirty()) {
            $preference->version = max(1, (int) $preference->version) + 1;
            $preference->save();
        }
    }

    private function authorize(User $user): void
    {
        Gate::forUser($user)->authorize('update-account-settings');

        if (! $this->schema->ready()) {
            abort(503, __('recommendations.feedback.unavailable'));
        }
    }

    private function hitRateLimit(User $user): void
    {
        $key = 'recommendation-preferences:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 20)) {
            throw ValidationException::withMessages([
                'recommendationPreferences' => __('recommendations.feedback.rate_limited'),
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}
