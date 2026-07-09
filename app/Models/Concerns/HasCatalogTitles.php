<?php

namespace App\Models\Concerns;

use App\Models\CatalogTitle;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasCatalogTitles
{
    /**
     * @return BelongsToMany<CatalogTitle, $this>
     */
    public function catalogTitles(): BelongsToMany
    {
        return $this->belongsToMany(
            CatalogTitle::class,
            $this->catalogTitlePivotTable(),
            $this->catalogTitleRelatedPivotKey(),
            'catalog_title_id',
        );
    }

    public function filterType(): string
    {
        return $this->catalogTitleFilterType();
    }

    abstract protected function catalogTitlePivotTable(): string;

    abstract protected function catalogTitleRelatedPivotKey(): string;

    abstract protected function catalogTitleFilterType(): string;
}
