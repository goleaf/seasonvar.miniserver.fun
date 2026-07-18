<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use Illuminate\Support\Facades\Schema;

final class ReviewSchema
{
    private ?bool $legacyAvailable = null;

    private ?bool $communityAvailable = null;

    private ?bool $writable = null;

    private ?bool $notificationsAvailable = null;

    public function legacyAvailable(): bool
    {
        return $this->legacyAvailable ??= Schema::hasTable('catalog_title_reviews');
    }

    public function communityAvailable(): bool
    {
        return $this->communityAvailable ??= $this->legacyAvailable()
            && Schema::hasColumns('catalog_title_reviews', [
                'user_id',
                'origin',
                'review_title',
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
                'deleted_at',
            ]);
    }

    public function writable(): bool
    {
        return $this->writable ??= $this->communityAvailable()
            && Schema::hasTable('catalog_title_review_aliases')
            && Schema::hasTable('catalog_title_review_votes')
            && Schema::hasTable('catalog_title_review_reports')
            && Schema::hasTable('catalog_title_review_restrictions')
            && Schema::hasTable('user_blocks')
            && Schema::hasTable('user_mutes');
    }

    public function notificationsAvailable(): bool
    {
        return $this->notificationsAvailable ??= $this->writable()
            && Schema::hasTable('notifications')
            && Schema::hasTable('catalog_title_review_notification_preferences');
    }
}
