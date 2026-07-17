<?php

declare(strict_types=1);

namespace App\DTOs\ReleaseCalendar;

final readonly class ReleaseCalendarNotificationData
{
    public function __construct(
        public string $id,
        public bool $isRead,
        public string $label,
        public ?string $detail,
        public string $url,
        public string $createdAtIso,
        public string $createdAtLabel,
    ) {}
}
