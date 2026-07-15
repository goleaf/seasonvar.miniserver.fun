<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

final readonly class CommentItemData
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public ?int $replyToId,
        public int $version,
        public CommentAuthorData $author,
        public ?string $replyToAuthor,
        public ?string $body,
        public bool $isSpoiler,
        public bool $spoilerRevealed,
        public bool $isLong,
        public bool $bodyExpanded,
        public bool $isDeleted,
        public bool $isHiddenByViewer,
        public bool $isUnavailable,
        public ?string $unavailableMessage,
        public ?string $moderationLabel,
        public string $createdAtIso,
        public string $createdAtLabel,
        public ?string $editedAtLabel,
        public int $replyCount,
        public int $visibleReplyCount,
        public CommentReactionSummaryData $reactions,
        public bool $canReply,
        public bool $canEdit,
        public bool $canDelete,
        public bool $canRestore,
        public bool $canReact,
        public bool $canReport,
        public bool $canBlock,
        public bool $canMute,
        public ?string $directUrl,
        public bool $isFocused,
    ) {}
}
