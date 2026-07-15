<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentReportCategory;
use App\Enums\CommentReportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $comment_id
 * @property int|null $reporter_id
 * @property int|null $moderator_id
 * @property CommentReportCategory $category
 * @property string|null $details
 * @property CommentReportStatus $status
 * @property string|null $private_note
 * @property string|null $deduplication_key
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'comment_id',
    'reporter_id',
    'moderator_id',
    'category',
    'details',
    'status',
    'private_note',
    'deduplication_key',
    'resolved_at',
])]
final class CommentReport extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => CommentReportStatus::Open->value];

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => CommentReportCategory::class,
            'status' => CommentReportStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
