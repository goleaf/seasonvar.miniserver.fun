<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogMetadataField;
use App\Enums\CatalogMetadataSourceKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $catalog_title_id
 * @property int|null $source_id
 * @property int|null $source_page_id
 * @property CatalogMetadataField $field_key
 * @property CatalogMetadataSourceKind $source_kind
 * @property string $source_key
 * @property mixed $value
 * @property string $value_hash
 * @property int $confidence
 * @property bool $is_current
 * @property bool $is_publication_eligible
 * @property CarbonImmutable $first_observed_at
 * @property CarbonImmutable $last_confirmed_at
 */
#[Fillable([
    'catalog_title_id',
    'source_id',
    'source_page_id',
    'field_key',
    'source_kind',
    'source_key',
    'value',
    'value_hash',
    'confidence',
    'is_current',
    'is_publication_eligible',
    'first_observed_at',
    'last_confirmed_at',
])]
final class CatalogMetadataObservation extends Model
{
    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return BelongsTo<SourcePage, $this> */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class);
    }

    /** @return HasMany<CatalogFieldVersion, $this> */
    public function fieldVersions(): HasMany
    {
        return $this->hasMany(CatalogFieldVersion::class, 'observation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'field_key' => CatalogMetadataField::class,
            'source_kind' => CatalogMetadataSourceKind::class,
            'value' => 'json',
            'confidence' => 'integer',
            'is_current' => 'boolean',
            'is_publication_eligible' => 'boolean',
            'first_observed_at' => 'immutable_datetime',
            'last_confirmed_at' => 'immutable_datetime',
        ];
    }
}
