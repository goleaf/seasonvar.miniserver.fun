<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_title_id',
    'recommended_title_id',
    'score',
    'rank',
    'reasons',
    'computed_at',
])]
class CatalogTitleRecommendation extends Model
{
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'rank' => 'integer',
            'reasons' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
