<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CommentNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class CommentActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly CommentNotificationType $kind,
        private readonly ?int $commentId = null,
        private readonly ?int $reactionId = null,
        private readonly ?int $reportId = null,
        private readonly ?string $moderationStatus = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'comment.activity';
    }

    /** @return array{kind: string, comment_id: int|null, reaction_id: int|null, report_id: int|null, moderation_status: string|null} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'comment_id' => $this->commentId,
            'reaction_id' => $this->reactionId,
            'report_id' => $this->reportId,
            'moderation_status' => $this->moderationStatus,
        ];
    }
}
