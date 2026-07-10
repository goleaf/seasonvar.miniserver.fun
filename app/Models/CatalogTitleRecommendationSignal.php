<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_title_id',
    'source',
    'signal_type',
    'signal_key',
    'signal_value',
    'weight',
    'observed_at',
])]
class CatalogTitleRecommendationSignal extends Model
{
    /**
     * @return BelongsTo<CatalogTitle, $this>
     */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /**
     * @param  Builder<CatalogTitleRecommendationSignal>  $query
     * @return Builder<CatalogTitleRecommendationSignal>
     */
    public function scopePositive(Builder $query): Builder
    {
        return $query->where('weight', '>', 0);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'observed_at' => 'datetime',
        ];
    }
}
