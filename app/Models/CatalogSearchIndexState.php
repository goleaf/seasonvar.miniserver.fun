<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogSearchIndexStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'version',
    'status',
    'source_count',
    'document_count',
    'checkpoint_id',
    'build_started_at',
    'completed_at',
    'failed_at',
    'last_error',
])]
class CatalogSearchIndexState extends Model
{
    public const SINGLETON_ID = 1;

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => CatalogSearchIndexStatus::class,
            'source_count' => 'integer',
            'document_count' => 'integer',
            'checkpoint_id' => 'integer',
            'build_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
