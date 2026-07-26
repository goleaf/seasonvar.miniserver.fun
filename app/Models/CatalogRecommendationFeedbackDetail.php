<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogRecommendationFeedbackReason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CatalogRecommendationFeedbackReason $reason
 * @property int|null $genre_id
 * @property int|null $country_id
 * @property int|null $actor_id
 * @property CatalogTitle|null $catalogTitle
 * @property Genre|null $genre
 * @property Country|null $country
 * @property Actor|null $actor
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id',
    'catalog_title_id',
    'reason',
    'genre_id',
    'country_id',
    'actor_id',
])]
class CatalogRecommendationFeedbackDetail extends Model
{
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

    /** @return BelongsTo<Genre, $this> */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<Actor, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => CatalogRecommendationFeedbackReason::class,
        ];
    }
}
