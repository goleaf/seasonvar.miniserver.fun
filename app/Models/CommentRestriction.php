<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentRestrictionReason;
use App\Enums\CommentRestrictionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $moderator_id
 * @property int|null $revoked_by_id
 * @property CommentRestrictionType $type
 * @property CommentRestrictionReason $reason_code
 * @property string|null $private_note
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Fillable([
    'user_id',
    'moderator_id',
    'revoked_by_id',
    'type',
    'reason_code',
    'private_note',
    'starts_at',
    'expires_at',
    'revoked_at',
])]
final class CommentRestriction extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_id');
    }

    /** @param Builder<CommentRestriction> $query */
    public function scopeActive(Builder $query): void
    {
        $query
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->starts_at->isPast()
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CommentRestrictionType::class,
            'reason_code' => CommentRestrictionReason::class,
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
