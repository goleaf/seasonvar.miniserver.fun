<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ContentRequestNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class ContentRequestActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ContentRequestNotificationType $kind,
        private readonly string $requestPublicId,
        private readonly ?string $status = null,
        private readonly ?string $canonicalPublicId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'content-request.activity';
    }

    /** @return array{kind: string, request_public_id: string, status: string|null, canonical_public_id: string|null} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'request_public_id' => $this->requestPublicId,
            'status' => $this->status,
            'canonical_public_id' => $this->canonicalPublicId,
        ];
    }
}
