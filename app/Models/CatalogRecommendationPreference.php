<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CatalogRecommendationDiversityPreference $diversity
 * @property CatalogRecommendationFreshnessPreference $freshness
 * @property CarbonImmutable|null $profile_reset_at
 */
#[Fillable([
    'user_id',
    'diversity',
    'freshness',
    'profile_reset_at',
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
            'version' => 'integer',
        ];
    }
}
