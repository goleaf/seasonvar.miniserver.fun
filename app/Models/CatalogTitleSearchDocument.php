<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_title_id',
    'title',
    'original_title',
    'aliases',
    'transliteration',
    'people',
    'taxonomies',
    'description',
    'suggestion_names',
    'normalized_title_key',
    'normalized_original_title_key',
    'normalized_alias_keys',
    'fingerprint',
])]
class CatalogTitleSearchDocument extends Model
{
    protected $primaryKey = 'catalog_title_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }
}
