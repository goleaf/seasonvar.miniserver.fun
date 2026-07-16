<?php

declare(strict_types=1);

namespace App\DTOs\ContentRequests;

final readonly class ContentRequestNotificationData
{
    public function __construct(
        public string $id,
        public bool $isRead,
        public string $label,
        public ?string $detail,
        public ?string $url,
        public string $createdAtIso,
        public string $createdAtLabel,
    ) {}
}
