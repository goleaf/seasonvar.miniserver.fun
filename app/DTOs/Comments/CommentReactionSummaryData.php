<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

use App\Enums\CommentReactionType;

final readonly class CommentReactionSummaryData
{
    public function __construct(
        public int $up,
        public int $down,
        public int $score,
        public ?CommentReactionType $viewerReaction,
    ) {}
}
