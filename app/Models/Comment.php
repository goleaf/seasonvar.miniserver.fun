<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentDeletionReason;
use App\Enums\CommentModerationReason;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Policies\CommentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $user_id
 * @property CommentTargetType $target_type
 * @property int $target_id
 * @property int|null $catalog_title_id
 * @property int|null $parent_id
 * @property int|null $reply_to_id
 * @property string $body
 * @property string $body_hash
 * @property bool $is_spoiler
 * @property CommentStatus $status
 * @property int $version
 * @property CarbonImmutable|null $edited_at
 * @property CommentDeletionReason|null $deletion_reason
 * @property int|null $deleted_by_id
 * @property int|null $moderated_by_id
 * @property CommentModerationReason|null $moderation_reason
 * @property string|null $moderator_note
 * @property CarbonImmutable|null $moderated_at
 * @property string $submission_key
 * @property CarbonImmutable|null $deleted_at
 */
#[UsePolicy(CommentPolicy::class)]
#[Fillable([
    'user_id',
    'target_type',
    'target_id',
    'catalog_title_id',
    'parent_id',
    'reply_to_id',
    'body',
    'body_hash',
    'is_spoiler',
    'status',
    'version',
    'edited_at',
    'deletion_reason',
    'deleted_by_id',
    'moderated_by_id',
    'moderation_reason',
    'moderator_note',
    'moderated_at',
    'submission_key',
])]
final class Comment extends Model
{
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => CommentStatus::Published->value,
        'version' => 1,
        'is_spoiler' => false,
    ];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class)->withTrashed();
    }

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    /** @return BelongsTo<Comment, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    /** @return HasMany<Comment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->withTrashed()
            ->oldest('created_at')
            ->orderBy('id');
    }

    /** @return HasMany<CommentReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    /** @return HasMany<CommentReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(CommentReport::class);
    }

    /** @param Builder<Comment> $query */
    public function scopeForTarget(Builder $query, CommentTargetType $type, int $targetId): void
    {
        $query->where('target_type', $type->value)->where('target_id', $targetId);
    }

    /** @param Builder<Comment> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', CommentStatus::Published->value);
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_type' => CommentTargetType::class,
            'user_id' => 'integer',
            'target_id' => 'integer',
            'catalog_title_id' => 'integer',
            'parent_id' => 'integer',
            'reply_to_id' => 'integer',
            'is_spoiler' => 'boolean',
            'status' => CommentStatus::class,
            'version' => 'integer',
            'edited_at' => 'immutable_datetime',
            'deletion_reason' => CommentDeletionReason::class,
            'moderation_reason' => CommentModerationReason::class,
            'moderated_at' => 'immutable_datetime',
            'deleted_by_id' => 'integer',
            'moderated_by_id' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
