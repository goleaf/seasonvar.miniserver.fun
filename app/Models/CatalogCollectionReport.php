<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogCollectionReportReason;
use App\Enums\CatalogCollectionReportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $catalog_collection_id
 * @property string $collection_public_id
 * @property int $collection_content_version
 * @property int|null $reporter_id
 * @property int|null $moderator_id
 * @property CatalogCollectionReportReason $reason
 * @property string|null $details
 * @property CatalogCollectionReportStatus $status
 * @property string|null $resolution_note
 * @property string $deduplication_key
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'catalog_collection_id',
    'collection_public_id',
    'collection_content_version',
    'reporter_id',
    'moderator_id',
    'reason',
    'details',
    'status',
    'resolution_note',
    'deduplication_key',
    'resolved_at',
])]
final class CatalogCollectionReport extends Model
{
    /** @return BelongsTo<CatalogCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(CatalogCollection::class, 'catalog_collection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => CatalogCollectionReportReason::class,
            'status' => CatalogCollectionReportStatus::class,
            'collection_content_version' => 'integer',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
