<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TagProviderMappingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $tag_id
 * @property string $provider
 * @property string $provider_key
 * @property string $raw_label
 * @property string|null $normalized_name
 * @property string|null $normalized_name_hash
 * @property string|null $source_url
 * @property TagProviderMappingStatus $status
 * @property int $confidence
 * @property CarbonImmutable|null $last_seen_at
 * @property-read Tag $tag
 */
#[Fillable([
    'provider', 'provider_key', 'tag_id', 'raw_label', 'normalized_name', 'normalized_name_hash', 'source_url',
    'status', 'confidence', 'last_seen_at',
])]
final class TagProviderMapping extends Model
{
    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TagProviderMappingStatus::class,
            'confidence' => 'integer',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
