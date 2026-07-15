<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentReactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $comment_id
 * @property int $user_id
 * @property CommentReactionType $type
 */
#[Fillable(['comment_id', 'user_id', 'type'])]
final class CommentReaction extends Model
{
    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => CommentReactionType::class];
    }
}
