<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentRelationshipData
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $createdAtLabel,
    ) {}
}
