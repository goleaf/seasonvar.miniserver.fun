<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TagSynonymRelationship;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tag_id
 * @property int $related_tag_id
 * @property TagSynonymRelationship $relationship
 * @property bool $is_bidirectional
 * @property int $priority
 */
#[Fillable(['tag_id', 'related_tag_id', 'relationship', 'is_bidirectional', 'priority'])]
final class TagSynonym extends Model
{
    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /** @return BelongsTo<Tag, $this> */
    public function relatedTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'related_tag_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'relationship' => TagSynonymRelationship::class,
            'is_bidirectional' => 'boolean',
            'priority' => 'integer',
        ];
    }
}
