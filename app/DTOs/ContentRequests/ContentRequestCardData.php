<?php

declare(strict_types=1);

namespace App\DTOs\ContentRequests;

final readonly class ContentRequestCardData
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $title,
        public ?string $originalTitle,
        public ?int $year,
        public string $type,
        public string $typeLabel,
        public string $status,
        public string $statusLabel,
        public int $votes,
        public int $followers,
        public bool $hasVoted,
        public bool $isFollowing,
        public bool $isRequester,
        public bool $canEngage,
        public ?string $targetLabel,
        public ?string $targetUrl,
        public string $url,
        public string $createdLabel,
        public string $updatedLabel,
    ) {}
}
