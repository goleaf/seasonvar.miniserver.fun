<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TagAliasSource;
use App\Enums\TagModerationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tag_id
 * @property string $public_id
 * @property string $locale
 * @property string $name
 * @property string $normalized_name
 * @property string $normalized_name_hash
 * @property string|null $slug
 * @property TagAliasSource $source
 * @property string|null $source_key
 * @property TagModerationStatus $moderation_status
 * @property-read Tag $tag
 */
#[Fillable([
    'public_id', 'tag_id', 'locale', 'name', 'normalized_name', 'normalized_name_hash', 'slug', 'source',
    'source_key', 'moderation_status',
])]
final class TagAlias extends Model
{
    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    protected static function booted(): void
    {
        self::creating(function (self $alias): void {
            $alias->public_id ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => TagAliasSource::class,
            'moderation_status' => TagModerationStatus::class,
        ];
    }
}
