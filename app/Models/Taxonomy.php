<?php

namespace App\Models;

use Database\Factories\TaxonomyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['type', 'name', 'slug', 'source_url'])]
class Taxonomy extends Model
{
    /** @use HasFactory<TaxonomyFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<CatalogTitle, $this>
     */
    public function catalogTitles(): BelongsToMany
    {
        return $this->belongsToMany(CatalogTitle::class);
    }
}
