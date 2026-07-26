<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationPreferenceData;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CatalogRecommendationPreferenceQuery
{
    /** @var array<int, CatalogRecommendationPreferenceData> */
    private array $resolved = [];

    public function __construct(private readonly CatalogRecommendationPreferenceSchema $schema) {}

    public function forUser(User $user): CatalogRecommendationPreferenceData
    {
        if (isset($this->resolved[$user->id])) {
            return $this->resolved[$user->id];
        }

        if (! $this->schema->ready()) {
            return CatalogRecommendationPreferenceData::defaults();
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
            ->get([
                'recommendation_preferences.diversity',
                'recommendation_preferences.freshness',
                'recommendation_preferences.profile_reset_at',
                'hidden_genres.genre_id',
            ]);
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

        return $this->resolved[$user->id] = new CatalogRecommendationPreferenceData(
            diversity: $diversity ?? CatalogRecommendationDiversityPreference::Balanced,
            freshness: $freshness ?? CatalogRecommendationFreshnessPreference::Balanced,
            profileResetAt: $resetAt,
            hiddenGenreIds: $hiddenGenreIds,
        );
    }

    public function forget(User $user): void
    {
        unset($this->resolved[$user->id]);
    }
}
