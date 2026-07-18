<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserProfileModerationStatus;
use App\Enums\UserProfileVisibility;
use App\Policies\UserProfilePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $user_id
 * @property string $username
 * @property string $normalized_username
 * @property string|null $biography
 * @property UserProfileVisibility $profile_visibility
 * @property UserProfileVisibility $biography_visibility
 * @property UserProfileVisibility $member_since_visibility
 * @property UserProfileVisibility $collections_visibility
 * @property UserProfileVisibility $reviews_visibility
 * @property UserProfileVisibility $comments_visibility
 * @property UserProfileVisibility $watching_visibility
 * @property UserProfileVisibility $completed_visibility
 * @property UserProfileVisibility $activity_visibility
 * @property UserProfileModerationStatus $moderation_status
 * @property string|null $avatar_disk
 * @property string|null $avatar_path
 * @property string|null $avatar_mime_type
 * @property int|null $avatar_size
 * @property int $avatar_version
 * @property string|null $cover_disk
 * @property string|null $cover_path
 * @property string|null $cover_mime_type
 * @property int|null $cover_size
 * @property int $cover_version
 * @property int $content_version
 */
#[UsePolicy(UserProfilePolicy::class)]
#[Fillable([
    'user_id',
    'username',
    'normalized_username',
    'biography',
    'profile_visibility',
    'biography_visibility',
    'member_since_visibility',
    'collections_visibility',
    'reviews_visibility',
    'comments_visibility',
    'watching_visibility',
    'completed_visibility',
    'activity_visibility',
])]
final class UserProfile extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'int';

    /** @var array<string, mixed> */
    protected $attributes = [
        'profile_visibility' => UserProfileVisibility::Private->value,
        'biography_visibility' => UserProfileVisibility::Private->value,
        'member_since_visibility' => UserProfileVisibility::Private->value,
        'collections_visibility' => UserProfileVisibility::Private->value,
        'reviews_visibility' => UserProfileVisibility::Private->value,
        'comments_visibility' => UserProfileVisibility::Private->value,
        'watching_visibility' => UserProfileVisibility::Private->value,
        'completed_visibility' => UserProfileVisibility::Private->value,
        'activity_visibility' => UserProfileVisibility::Private->value,
        'moderation_status' => UserProfileModerationStatus::Active->value,
        'avatar_version' => 0,
        'cover_version' => 0,
        'content_version' => 1,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<UserProfileUsernameHistory, $this> */
    public function usernameHistory(): HasMany
    {
        return $this->hasMany(UserProfileUsernameHistory::class, 'user_id');
    }

    /** @return HasMany<UserProfileReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(UserProfileReport::class, 'target_user_id');
    }

    /** @param Builder<UserProfile> $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query
            ->where('profile_visibility', UserProfileVisibility::Public->value)
            ->where('moderation_status', UserProfileModerationStatus::Active->value);
    }

    public function isPublic(): bool
    {
        return $this->profile_visibility === UserProfileVisibility::Public
            && $this->moderation_status === UserProfileModerationStatus::Active;
    }

    public function sectionIsPublic(string $section): bool
    {
        $column = $section.'_visibility';

        return $this->isPublic()
            && array_key_exists($column, $this->getAttributes())
            && $this->getAttribute($column) === UserProfileVisibility::Public;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'profile_visibility' => UserProfileVisibility::class,
            'biography_visibility' => UserProfileVisibility::class,
            'member_since_visibility' => UserProfileVisibility::class,
            'collections_visibility' => UserProfileVisibility::class,
            'reviews_visibility' => UserProfileVisibility::class,
            'comments_visibility' => UserProfileVisibility::class,
            'watching_visibility' => UserProfileVisibility::class,
            'completed_visibility' => UserProfileVisibility::class,
            'activity_visibility' => UserProfileVisibility::class,
            'moderation_status' => UserProfileModerationStatus::class,
            'avatar_version' => 'integer',
            'cover_version' => 'integer',
            'content_version' => 'integer',
        ];
    }
}
