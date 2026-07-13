<?php

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'base_url', 'is_active', 'crawl_delay_seconds', 'settings'])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * @return HasMany<SourcePage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(SourcePage::class);
    }

    /**
     * @return HasMany<CatalogTitle, $this>
     */
    public function catalogTitles(): HasMany
    {
        return $this->hasMany(CatalogTitle::class);
    }

    /**
     * @return HasMany<CatalogRelationSourceIdentity, $this>
     */
    public function catalogRelationSourceIdentities(): HasMany
    {
        return $this->hasMany(CatalogRelationSourceIdentity::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'crawl_delay_seconds' => 'integer',
            'settings' => 'array',
        ];
    }
}
