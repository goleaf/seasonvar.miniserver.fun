<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogCollectionQualityIssueSeverity;
use App\Enums\CatalogCollectionQualityIssueStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $catalog_collection_id
 * @property int|null $related_catalog_collection_id
 * @property string $code
 * @property CatalogCollectionQualityIssueSeverity $severity
 * @property CatalogCollectionQualityIssueStatus $status
 * @property string $fingerprint
 * @property array<string, int|float|string|bool|null>|null $evidence
 * @property CarbonImmutable|null $first_detected_at
 * @property CarbonImmutable|null $last_detected_at
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'catalog_collection_id',
    'related_catalog_collection_id',
    'code',
    'severity',
    'status',
    'fingerprint',
    'evidence',
    'first_detected_at',
    'last_detected_at',
    'resolved_at',
])]
final class CatalogCollectionQualityIssue extends Model
{
    /** @return BelongsTo<CatalogCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(CatalogCollection::class, 'catalog_collection_id');
    }

    /** @return BelongsTo<CatalogCollection, $this> */
    public function relatedCollection(): BelongsTo
    {
        return $this->belongsTo(CatalogCollection::class, 'related_catalog_collection_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'severity' => CatalogCollectionQualityIssueSeverity::class,
            'status' => CatalogCollectionQualityIssueStatus::class,
            'evidence' => 'array',
            'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
