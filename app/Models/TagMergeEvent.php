<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_tag_id', 'target_tag_id', 'actor_id', 'snapshot', 'occurred_at'])]
final class TagMergeEvent extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Tag, $this> */
    public function sourceTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'source_tag_id');
    }

    /** @return BelongsTo<Tag, $this> */
    public function targetTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'target_tag_id');
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
            'snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
