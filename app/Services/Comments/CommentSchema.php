<?php

declare(strict_types=1);

namespace App\Services\Comments;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class CommentSchema
{
    /** @var list<string> */
    private const COMMENT_COLUMNS = [
        'id',
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
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    private ?bool $available = null;

    private ?bool $engagementAvailable = null;

    private ?bool $relationshipsAvailable = null;

    private ?bool $notificationsAvailable = null;

    public function available(): bool
    {
        return $this->available ??= $this->hasTableWithColumns('comments', self::COMMENT_COLUMNS);
    }

    public function writable(): bool
    {
        return (bool) config('comments.enabled', true)
            && $this->available()
            && $this->engagementAvailable()
            && $this->relationshipsAvailable()
            && $this->notificationsAvailable();
    }

    public function engagementAvailable(): bool
    {
        return $this->engagementAvailable ??=
            $this->hasTableWithColumns('comment_reactions', [
                'id', 'comment_id', 'user_id', 'type', 'created_at', 'updated_at',
            ])
            && $this->hasTableWithColumns('comment_reports', [
                'id', 'comment_id', 'reporter_id', 'moderator_id', 'category', 'details',
                'status', 'private_note', 'deduplication_key', 'resolved_at', 'created_at', 'updated_at',
            ])
            && $this->hasTableWithColumns('comment_restrictions', [
                'id', 'user_id', 'moderator_id', 'revoked_by_id', 'type', 'reason_code',
                'private_note', 'starts_at', 'expires_at', 'revoked_at', 'created_at', 'updated_at',
            ]);
    }

    public function relationshipsAvailable(): bool
    {
        return $this->relationshipsAvailable ??=
            $this->hasTableWithColumns('user_blocks', [
                'id', 'blocker_id', 'blocked_id', 'created_at', 'updated_at',
            ])
            && $this->hasTableWithColumns('user_mutes', [
                'id', 'muter_id', 'muted_id', 'created_at', 'updated_at',
            ]);
    }

    public function notificationsAvailable(): bool
    {
        return $this->notificationsAvailable ??=
            $this->hasTableWithColumns('notifications', [
                'id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at', 'updated_at',
            ])
            && $this->hasTableWithColumns('comment_notification_preferences', [
                'user_id', 'reply_notifications', 'reaction_notifications',
                'moderation_notifications', 'report_notifications', 'created_at', 'updated_at',
            ]);
    }

    /** @param list<string> $columns */
    private function hasTableWithColumns(string $table, array $columns): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumns($table, $columns);
        } catch (Throwable) {
            return false;
        }
    }
}
