<?php

declare(strict_types=1);

namespace App\Services\Comments;

use Illuminate\Support\Facades\Schema;

final class CommentSchema
{
    private ?bool $available = null;

    private ?bool $engagementAvailable = null;

    private ?bool $relationshipsAvailable = null;

    private ?bool $notificationsAvailable = null;

    public function available(): bool
    {
        return $this->available ??= (bool) config('comments.enabled', true)
            && Schema::hasTable('comments');
    }

    public function writable(): bool
    {
        return $this->available()
            && $this->engagementAvailable()
            && $this->relationshipsAvailable();
    }

    public function engagementAvailable(): bool
    {
        return $this->engagementAvailable ??= Schema::hasTable('comment_reactions')
            && Schema::hasTable('comment_reports')
            && Schema::hasTable('comment_restrictions');
    }

    public function relationshipsAvailable(): bool
    {
        return $this->relationshipsAvailable ??= Schema::hasTable('user_blocks')
            && Schema::hasTable('user_mutes');
    }

    public function notificationsAvailable(): bool
    {
        return $this->notificationsAvailable ??= Schema::hasTable('notifications')
            && Schema::hasTable('comment_notification_preferences');
    }
}
