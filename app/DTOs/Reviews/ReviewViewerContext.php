<?php

declare(strict_types=1);

namespace App\DTOs\Reviews;

final readonly class ReviewViewerContext
{
    /**
     * @param  list<int>  $blockedUserIds
     * @param  list<int>  $mutedUserIds
     */
    public function __construct(
        public ?int $userId,
        public bool $isModerator,
        public bool $isReviewRestricted,
        public array $blockedUserIds,
        public array $mutedUserIds,
    ) {}

    public function hides(?int $authorId): bool
    {
        return $authorId !== null && (
            in_array($authorId, $this->blockedUserIds, true)
            || in_array($authorId, $this->mutedUserIds, true)
        );
    }
}
