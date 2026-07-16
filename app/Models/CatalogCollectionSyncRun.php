<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogCollectionSyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'provider',
    'status',
    'counters',
    'error_summary',
    'started_at',
    'completed_at',
])]
final class CatalogCollectionSyncRun extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => CatalogCollectionSyncStatus::Running->value,
    ];

    /** @return HasMany<CatalogCollectionSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(CatalogCollectionSource::class, 'last_seen_run_id');
    }

    /** @return HasMany<CatalogCollectionSourceItem, $this> */
    public function sourceItems(): HasMany
    {
        return $this->hasMany(CatalogCollectionSourceItem::class, 'last_seen_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CatalogCollectionSyncStatus::class,
            'counters' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
