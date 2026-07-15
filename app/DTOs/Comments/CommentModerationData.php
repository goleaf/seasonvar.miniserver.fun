<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentModerationData
{
    /** @param list<CommentReportModerationData> $reports */
    public function __construct(
        public int $id,
        public ?int $authorId,
        public string $authorName,
        public string $targetLabel,
        public string $body,
        public bool $isSpoiler,
        public bool $isDeleted,
        public string $statusValue,
        public string $statusLabel,
        public ?string $moderationReasonLabel,
        public ?string $privateNote,
        public int $replyCount,
        public int $reportCount,
        public array $reports,
        public ?CommentRestrictionData $activeRestriction,
        public string $createdAtIso,
        public string $createdAtLabel,
        public ?string $editedAtLabel,
        public string $directUrl,
    ) {}
}
