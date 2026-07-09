<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogTitles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'source_url'])]
class Country extends Model
{
    use HasCatalogTitles;

    protected function catalogTitlePivotTable(): string
    {
        return 'catalog_title_country';
    }

    protected function catalogTitleRelatedPivotKey(): string
    {
        return 'country_id';
    }

    protected function catalogTitleFilterType(): string
    {
        return 'country';
    }
}
