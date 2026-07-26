<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogCollectionQualityRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'status',
    'scanned_count',
    'assessed_count',
    'opened_issue_count',
    'resolved_issue_count',
    'error_message',
    'started_by_id',
    'started_at',
    'completed_at',
])]
final class CatalogCollectionQualityRun extends Model
{
    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CatalogCollectionQualityRunStatus::class,
            'scanned_count' => 'integer',
            'assessed_count' => 'integer',
            'opened_issue_count' => 'integer',
            'resolved_issue_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
