<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentReportModerationData
{
    public function __construct(
        public int $id,
        public string $categoryLabel,
        public ?string $details,
        public string $statusLabel,
        public string $createdAtLabel,
    ) {}
}
