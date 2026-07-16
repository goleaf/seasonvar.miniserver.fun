<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogCollectionSourceMatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_collection_source_id',
    'source_item_key',
    'source_title',
    'normalized_title_key',
    'normalized_title_hash',
    'source_year',
    'source_type',
    'countries',
    'detail_path',
    'detail_path_hash',
    'source_page',
    'source_position',
    'match_status',
    'catalog_title_id',
    'match_method',
    'match_confidence',
    'match_reasons',
    'last_seen_run_id',
])]
final class CatalogCollectionSourceItem extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'match_status' => CatalogCollectionSourceMatchStatus::Unmatched->value,
    ];

    /** @return BelongsTo<CatalogCollectionSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CatalogCollectionSource::class, 'catalog_collection_source_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<CatalogCollectionSyncRun, $this> */
    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(CatalogCollectionSyncRun::class, 'last_seen_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_year' => 'integer',
            'countries' => 'array',
            'source_page' => 'integer',
            'source_position' => 'integer',
            'match_status' => CatalogCollectionSourceMatchStatus::class,
            'match_confidence' => 'integer',
            'match_reasons' => 'array',
        ];
    }
}
