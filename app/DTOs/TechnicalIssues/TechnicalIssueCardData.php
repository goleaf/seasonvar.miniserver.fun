<?php

declare(strict_types=1);

namespace App\DTOs\TechnicalIssues;

final readonly class TechnicalIssueCardData
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $number,
        public string $type,
        public string $typeLabel,
        public string $status,
        public string $statusLabel,
        public string $severity,
        public string $severityLabel,
        public string $priority,
        public string $priorityLabel,
        public string $targetLabel,
        public ?string $summary,
        public string $createdAt,
        public string $updatedAt,
        public int $attachmentCount,
        public int $messageCount,
        public int $confirmationCount,
        public int $affectedUserCount,
        public bool $isFollowing,
        public bool $hasConfirmed,
        public bool $needsRequesterResponse,
        public bool $isAssigned,
        public ?string $requesterName,
        public ?string $sourceHealth,
        public string $url,
    ) {}
}
