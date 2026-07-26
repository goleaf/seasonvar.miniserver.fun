<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogMetadataConflictStatus;
use App\Enums\CatalogMetadataField;
use App\Enums\CatalogQualitySeverity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $catalog_title_id
 * @property CatalogMetadataField $field_key
 * @property int|null $selected_observation_id
 * @property int|null $competing_observation_id
 * @property string $selected_value_hash
 * @property string $competing_value_hash
 * @property CatalogQualitySeverity $severity
 * @property CatalogMetadataConflictStatus $status
 * @property CarbonImmutable $first_detected_at
 * @property CarbonImmutable $last_detected_at
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'catalog_title_id',
    'field_key',
    'selected_observation_id',
    'competing_observation_id',
    'selected_value_hash',
    'competing_value_hash',
    'severity',
    'status',
    'first_detected_at',
    'last_detected_at',
    'resolved_at',
])]
final class CatalogMetadataConflict extends Model
{
    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<CatalogMetadataObservation, $this> */
    public function selectedObservation(): BelongsTo
    {
        return $this->belongsTo(CatalogMetadataObservation::class, 'selected_observation_id');
    }

    /** @return BelongsTo<CatalogMetadataObservation, $this> */
    public function competingObservation(): BelongsTo
    {
        return $this->belongsTo(CatalogMetadataObservation::class, 'competing_observation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'field_key' => CatalogMetadataField::class,
            'severity' => CatalogQualitySeverity::class,
            'status' => CatalogMetadataConflictStatus::class,
            'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
