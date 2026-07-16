<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogWatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'catalog_title_id',
    'in_watchlist',
    'rating',
    'watchlist_version',
    'watchlist_updated_at',
    'rating_version',
    'rating_updated_at',
    'recommendation_feedback',
    'recommendation_feedback_version',
    'recommendation_feedback_updated_at',
    'watch_status',
    'watch_status_version',
    'watch_status_updated_at',
])]
class CatalogTitleUserState extends Model
{
    /** @var array<string, int> */
    protected $attributes = [
        'watchlist_version' => 0,
        'rating_version' => 0,
        'recommendation_feedback_version' => 0,
        'watch_status_version' => 0,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    public function watchlistVersion(): int
    {
        return $this->versionAttribute('watchlist_version');
    }

    public function ratingVersion(): int
    {
        return $this->versionAttribute('rating_version');
    }

    public function recommendationFeedbackVersion(): int
    {
        return $this->versionAttribute('recommendation_feedback_version');
    }

    public function watchStatusVersion(): int
    {
        return $this->versionAttribute('watch_status_version');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'in_watchlist' => 'boolean',
            'rating' => 'integer',
            'watchlist_version' => 'integer',
            'watchlist_updated_at' => 'datetime',
            'rating_version' => 'integer',
            'rating_updated_at' => 'datetime',
            'recommendation_feedback' => CatalogRecommendationFeedback::class,
            'recommendation_feedback_version' => 'integer',
            'recommendation_feedback_updated_at' => 'datetime',
            'watch_status' => CatalogWatchStatus::class,
            'watch_status_version' => 'integer',
            'watch_status_updated_at' => 'datetime',
        ];
    }

    private function versionAttribute(string $attribute): int
    {
        if (! array_key_exists($attribute, $this->getAttributes())) {
            return 0;
        }

        $value = $this->getAttribute($attribute);

        return is_int($value) && $value >= 0 ? $value : 0;
    }
}
