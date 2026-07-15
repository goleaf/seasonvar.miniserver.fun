<?php

declare(strict_types=1);

namespace App\DTOs\Reviews;

final readonly class ReviewItemData
{
    public function __construct(
        public int $id,
        public string $origin,
        public string $scopeLabel,
        public string $authorName,
        public string $authorInitial,
        public ?string $title,
        public ?string $body,
        public bool $bodyHidden,
        public bool $isSpoiler,
        public bool $isVerifiedWatching,
        public ?int $rating,
        public int $ratingMaximum,
        public string $status,
        public string $statusLabel,
        public string $publishedLabel,
        public bool $isEdited,
        public bool $isDeleted,
        public bool $isOwn,
        public int $helpfulCount,
        public int $notHelpfulCount,
        public int $helpfulnessScore,
        public ?string $viewerVote,
        public ?string $directUrl,
        public ?string $targetUrl,
        public ?string $targetTitle,
        public bool $isHighlighted,
        public bool $canReveal,
        public bool $canEdit,
        public bool $canDelete,
        public bool $canRestore,
        public bool $canVote,
        public bool $canReport,
        public bool $canModerate,
        public int $version,
    ) {}
}
