<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Enums\CatalogRecommendationPlaybackPreference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CatalogRecommendationDiversityPreference $diversity
 * @property CatalogRecommendationFreshnessPreference $freshness
 * @property CarbonImmutable|null $profile_reset_at
 * @property CarbonImmutable|null $onboarding_completed_at
 * @property CatalogRecommendationPlaybackPreference $playback_preference
 * @property CatalogRecommendationCompletionPreference $completion_preference
 * @property CatalogRecommendationEpisodeLengthPreference $episode_length_preference
 */
#[Fillable([
    'user_id',
    'diversity',
    'freshness',
    'profile_reset_at',
    'onboarding_completed_at',
    'playback_preference',
    'completion_preference',
    'episode_length_preference',
    'version',
])]
class CatalogRecommendationPreference extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'int';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diversity' => CatalogRecommendationDiversityPreference::class,
            'freshness' => CatalogRecommendationFreshnessPreference::class,
            'profile_reset_at' => 'immutable_datetime',
            'onboarding_completed_at' => 'immutable_datetime',
            'playback_preference' => CatalogRecommendationPlaybackPreference::class,
            'completion_preference' => CatalogRecommendationCompletionPreference::class,
            'episode_length_preference' => CatalogRecommendationEpisodeLengthPreference::class,
            'version' => 'integer',
        ];
    }
}
