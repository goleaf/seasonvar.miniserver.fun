<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentRestrictionData
{
    public function __construct(
        public int $id,
        public string $typeLabel,
        public string $reasonLabel,
        public ?string $expiresAtLabel,
    ) {}
}
