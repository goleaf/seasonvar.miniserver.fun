<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'provider',
    'source_key',
    'catalog_collection_id',
    'source_path',
    'remote_name',
    'cover_source_path',
    'cover_path',
    'cover_content_hash',
    'semantic_content_hash',
    'retry_count',
    'last_retry_at',
    'last_seen_run_id',
    'last_successful_sync_at',
    'missing_since_at',
])]
final class CatalogCollectionSource extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'retry_count' => 0,
    ];

    /** @return BelongsTo<CatalogCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(CatalogCollection::class, 'catalog_collection_id');
    }

    /** @return HasMany<CatalogCollectionSourceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogCollectionSourceItem::class)
            ->orderBy('source_position')
            ->orderBy('id');
    }

    /** @return HasMany<CatalogCollectionSyncRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(CatalogCollectionSyncRun::class, 'provider', 'provider')
            ->latest('started_at')
            ->latest('id');
    }

    /** @return BelongsTo<CatalogCollectionSyncRun, $this> */
    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(CatalogCollectionSyncRun::class, 'last_seen_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'retry_count' => 'integer',
            'last_retry_at' => 'immutable_datetime',
            'last_successful_sync_at' => 'immutable_datetime',
            'missing_since_at' => 'immutable_datetime',
        ];
    }
}
