<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogTitles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'source_url'])]
class AgeRating extends Model
{
    use HasCatalogTitles;

    protected function catalogTitlePivotTable(): string
    {
        return 'age_rating_catalog_title';
    }

    protected function catalogTitleRelatedPivotKey(): string
    {
        return 'age_rating_id';
    }

    protected function catalogTitleFilterType(): string
    {
        return 'age_rating';
    }
}
