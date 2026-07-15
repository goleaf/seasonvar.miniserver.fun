<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TagSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $catalog_title_id
 * @property int $tag_id
 * @property TagSource $source
 * @property string|null $provider
 * @property int|null $source_id
 * @property string $source_key
 * @property bool $is_current
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
#[Fillable([
    'catalog_title_id', 'tag_id', 'source', 'provider', 'source_id', 'source_key', 'is_current',
    'first_seen_at', 'last_seen_at',
])]
final class CatalogTitleTagSource extends Model
{
    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function providerSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => TagSource::class,
            'is_current' => 'boolean',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
