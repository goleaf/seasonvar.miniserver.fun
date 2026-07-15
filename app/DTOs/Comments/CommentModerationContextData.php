<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentModerationContextData
{
    public function __construct(
        public int $id,
        public string $authorName,
        public string $body,
        public string $statusLabel,
        public bool $isDeleted,
        public bool $isSelected,
        public string $createdAtIso,
        public string $createdAtLabel,
    ) {}
}
