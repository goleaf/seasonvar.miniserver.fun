<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ReleaseCalendarNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class ReleaseCalendarActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ReleaseCalendarNotificationType $kind,
        private readonly string $entryPublicId,
        private readonly string $entryType,
        private readonly string $status,
        private readonly int $revision,
        private readonly ?string $previousDate = null,
        private readonly ?string $newDate = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'release-calendar.activity';
    }

    /** @return array<string, int|string|null> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'entry_public_id' => $this->entryPublicId,
            'entry_type' => $this->entryType,
            'status' => $this->status,
            'revision' => $this->revision,
            'previous_date' => $this->previousDate,
            'new_date' => $this->newDate,
        ];
    }
}
