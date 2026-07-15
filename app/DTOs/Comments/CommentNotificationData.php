<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentNotificationData
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $detail,
        public ?string $url,
        public bool $isRead,
        public string $createdAtIso,
        public string $createdAtLabel,
    ) {}
}
