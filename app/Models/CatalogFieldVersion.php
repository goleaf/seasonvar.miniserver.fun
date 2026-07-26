<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogMetadataField;
use App\Enums\CatalogMetadataSourceKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $catalog_title_id
 * @property CatalogMetadataField $field_key
 * @property int $version
 * @property int|null $observation_id
 * @property int|null $actor_id
 * @property CatalogMetadataSourceKind $source_kind
 * @property mixed $value
 * @property string $value_hash
 * @property CarbonImmutable $selected_at
 * @property CarbonImmutable|null $superseded_at
 */
#[Fillable([
    'catalog_title_id',
    'field_key',
    'version',
    'observation_id',
    'actor_id',
    'source_kind',
    'value',
    'value_hash',
    'selected_at',
    'superseded_at',
])]
final class CatalogFieldVersion extends Model
{
    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<CatalogMetadataObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(CatalogMetadataObservation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'field_key' => CatalogMetadataField::class,
            'source_kind' => CatalogMetadataSourceKind::class,
            'version' => 'integer',
            'value' => 'json',
            'selected_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
