<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewDeletionReason;
use App\Enums\ReviewModerationReason;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Policies\CatalogTitleReviewPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsePolicy(CatalogTitleReviewPolicy::class)]
#[Fillable([
    'catalog_title_id',
    'source_page_id',
    'user_id',
    'origin',
    'author',
    'review_title',
    'body',
    'body_hash',
    'original_body_hash',
    'is_spoiler',
    'is_verified_watch',
    'status',
    'version',
    'edited_at',
    'deletion_reason',
    'deleted_by_id',
    'moderated_by_id',
    'moderation_reason',
    'moderator_note',
    'moderated_at',
    'ownership_key',
    'submission_key',
    'merged_into_id',
    'status_before_merge',
    'deletion_reason_before_merge',
    'ownership_released_at',
    'published_at',
    'deleted_at',
])]
final class CatalogTitleReview extends Model
{
    /**
     * @return BelongsTo<CatalogTitle, $this>
     */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /**
     * @return BelongsTo<SourcePage, $this>
     */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function authorAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_id');
    }

    /** @return BelongsTo<CatalogTitleReview, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** @return HasMany<CatalogTitleReviewVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(CatalogTitleReviewVote::class);
    }

    /** @return HasMany<CatalogTitleReviewReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(CatalogTitleReviewReport::class);
    }

    /** @param Builder<CatalogTitleReview> $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query
            ->where('status', ReviewStatus::Published->value)
            ->whereNull('deleted_at')
            ->whereNull('merged_into_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'origin' => ReviewOrigin::class,
            'is_spoiler' => 'boolean',
            'is_verified_watch' => 'boolean',
            'status' => ReviewStatus::class,
            'version' => 'integer',
            'edited_at' => 'immutable_datetime',
            'deletion_reason' => ReviewDeletionReason::class,
            'deleted_by_id' => 'integer',
            'moderated_by_id' => 'integer',
            'moderation_reason' => ReviewModerationReason::class,
            'moderated_at' => 'immutable_datetime',
            'merged_into_id' => 'integer',
            'ownership_released_at' => 'immutable_datetime',
            'published_at' => 'datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
