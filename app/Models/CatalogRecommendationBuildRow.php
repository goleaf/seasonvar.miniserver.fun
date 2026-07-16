<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'build_id',
    'catalog_title_id',
    'recommended_title_id',
    'score',
    'rank',
    'matched_features_count',
    'metadata_score',
    'source_score',
    'quality_score',
    'reasons',
    'computed_at',
])]
final class CatalogRecommendationBuildRow extends Model
{
    /** @return BelongsTo<CatalogRecommendationBuild, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(CatalogRecommendationBuild::class, 'build_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function recommendedTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class, 'recommended_title_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'rank' => 'integer',
            'matched_features_count' => 'integer',
            'metadata_score' => 'integer',
            'source_score' => 'integer',
            'quality_score' => 'integer',
            'reasons' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
