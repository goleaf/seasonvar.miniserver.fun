<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ReviewNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class ReviewActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ReviewNotificationType $kind,
        private readonly int $reviewId,
        private readonly ?int $voteId = null,
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
        return 'review.activity';
    }

    /** @return array{kind: string, review_id: int, vote_id: int|null, report_id: int|null, moderation_status: string|null} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'review_id' => $this->reviewId,
            'vote_id' => $this->voteId,
            'report_id' => $this->reportId,
            'moderation_status' => $this->moderationStatus,
        ];
    }
}
