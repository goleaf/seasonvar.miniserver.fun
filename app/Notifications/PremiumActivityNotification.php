<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\PremiumNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class PremiumActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly PremiumNotificationType $kind,
        private readonly string $resourcePublicId,
        private readonly ?string $expiresAt,
        private readonly bool $lifetime,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'premium.activity';
    }

    /** @return array<string, bool|string|null> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'resource_public_id' => $this->resourcePublicId,
            'expires_at' => $this->expiresAt,
            'lifetime' => $this->lifetime,
        ];
    }
}
