<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $catalog_collection_id
 * @property int $catalog_title_id
 * @property int|null $added_by_id
 * @property int $position
 * @property int|null $theme_match_percent
 * @property string|null $inclusion_reason_code
 * @property int|null $quality_content_version
 * @property-read int $aggregate
 * @property-read int $maximum_position
 */
#[Fillable([
    'catalog_collection_id',
    'catalog_title_id',
    'added_by_id',
    'position',
])]
final class CatalogCollectionItem extends Model
{
    /** @return BelongsTo<CatalogCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(CatalogCollection::class, 'catalog_collection_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitleWithTrashed(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class, 'catalog_title_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'theme_match_percent' => 'integer',
            'quality_content_version' => 'integer',
        ];
    }
}
