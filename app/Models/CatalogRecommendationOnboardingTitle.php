<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogRecommendationOnboardingTitleKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CatalogRecommendationOnboardingTitleKind $kind
 */
#[Fillable(['user_id', 'catalog_title_id', 'kind'])]
final class CatalogRecommendationOnboardingTitle extends Model
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => CatalogRecommendationOnboardingTitleKind::class];
    }
}
