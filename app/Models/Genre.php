<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogTitles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'source_url'])]
class Genre extends Model
{
    use HasCatalogTitles;

    protected function catalogTitlePivotTable(): string
    {
        return 'catalog_title_genre';
    }

    protected function catalogTitleRelatedPivotKey(): string
    {
        return 'genre_id';
    }

    protected function catalogTitleFilterType(): string
    {
        return 'genre';
    }
}
