<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentAuthorData
{
    public function __construct(
        public ?int $id,
        public string $name,
        public bool $isUnavailable,
    ) {}
}
