<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentModerationThreadData
{
    /** @param list<CommentModerationContextData> $items */
    public function __construct(
        public int $rootId,
        public int $replyCount,
        public array $items,
        public bool $hasMore,
    ) {}
}
