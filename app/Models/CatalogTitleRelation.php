<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogTitleRelationSource;
use App\Enums\CatalogTitleRelationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source_title_id',
    'target_title_id',
    'relation_type',
    'source',
    'provider_key',
    'priority',
    'is_locked',
    'is_active',
])]
final class CatalogTitleRelation extends Model
{
    public function relationType(): CatalogTitleRelationType
    {
        return CatalogTitleRelationType::from((string) $this->getRawOriginal('relation_type'));
    }

    public function relationSource(): CatalogTitleRelationSource
    {
        return CatalogTitleRelationSource::from((string) $this->getRawOriginal('source'));
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function sourceTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class, 'source_title_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function targetTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class, 'target_title_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'relation_type' => CatalogTitleRelationType::class,
            'source' => CatalogTitleRelationSource::class,
            'priority' => 'integer',
            'is_locked' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
