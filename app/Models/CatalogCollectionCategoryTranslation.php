<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_collection_category_id',
    'locale',
    'name',
])]
final class CatalogCollectionCategoryTranslation extends Model
{
    /** @return BelongsTo<CatalogCollectionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCollectionCategory::class, 'catalog_collection_category_id');
    }
}
