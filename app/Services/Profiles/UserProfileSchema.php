<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class UserProfileSchema
{
    /** @var list<string> */
    private const PROFILE_COLUMNS = [
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
        'moderation_status',
        'avatar_disk',
        'avatar_path',
        'avatar_mime_type',
        'avatar_size',
        'avatar_version',
        'cover_disk',
        'cover_path',
        'cover_mime_type',
        'cover_size',
        'cover_version',
        'content_version',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const USERNAME_HISTORY_COLUMNS = [
        'id',
        'user_id',
        'username',
        'normalized_username',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const REPORT_COLUMNS = [
        'id',
        'public_id',
        'target_user_id',
        'target_public_id',
        'reporter_id',
        'moderator_id',
        'category',
        'details',
        'status',
        'private_note',
        'deduplication_key',
        'resolved_at',
        'created_at',
        'updated_at',
    ];

    private ?bool $available = null;

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = Schema::hasColumns('users', ['id', 'public_id', 'name', 'email_verified_at', 'created_at'])
                && Schema::hasColumns('user_profiles', self::PROFILE_COLUMNS)
                && Schema::hasColumns('user_profile_username_histories', self::USERNAME_HISTORY_COLUMNS)
                && Schema::hasColumns('user_profile_reports', self::REPORT_COLUMNS);
        } catch (Throwable) {
            return $this->available = false;
        }
    }
}
