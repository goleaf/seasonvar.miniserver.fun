<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class AccountRestrictionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $kind,
        private readonly string $restrictionPublicId,
        private readonly string $noticeKey,
        private readonly ?string $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'account.restriction';
    }

    /** @return array<string, string|null> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'restriction_public_id' => $this->restrictionPublicId,
            'notice_key' => $this->noticeKey,
            'expires_at' => $this->expiresAt,
        ];
    }
}
