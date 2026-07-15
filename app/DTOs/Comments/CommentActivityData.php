<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentActivityData
{
    public function __construct(
        public int $id,
        public string $targetLabel,
        public ?string $excerpt,
        public bool $isSpoiler,
        public string $statusLabel,
        public string $statusVariant,
        public string $createdAtIso,
        public string $createdAtLabel,
        public ?string $editedAtLabel,
        public string $directUrl,
    ) {}
}
