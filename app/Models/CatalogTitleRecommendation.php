<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Catalog\CatalogRecommendationPresenter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_title_id',
    'recommended_title_id',
    'score',
    'rank',
    'algorithm_version',
    'matched_features_count',
    'metadata_score',
    'source_score',
    'quality_score',
    'reasons',
    'computed_at',
])]
class CatalogTitleRecommendation extends Model
{
    protected $attributes = [
        'algorithm_version' => 'v1',
    ];

    /**
     * @return BelongsTo<CatalogTitle, $this>
     */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /**
     * @return BelongsTo<CatalogTitle, $this>
     */
    public function recommendedTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class, 'recommended_title_id');
    }

    /**
     * @return list<string>
     */
    public function reasonLabels(): array
    {
        return app(CatalogRecommendationPresenter::class)->storedSimilarityReasons(
            $this->getAttribute('reasons'),
        );
    }

    /**
     * @return array{metadata: int, source: int, quality: int, total: int}
     */
    public function scoreBreakdown(): array
    {
        return [
            'metadata' => (int) $this->metadata_score,
            'source' => (int) $this->source_score,
            'quality' => (int) $this->quality_score,
            'total' => (int) $this->score,
        ];
    }

    /**
     * @return array<string, string>
     */
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
