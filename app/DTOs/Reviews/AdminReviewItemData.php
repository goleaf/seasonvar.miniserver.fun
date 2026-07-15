<?php

declare(strict_types=1);

namespace App\DTOs\Reviews;

final readonly class AdminReviewItemData
{
    /**
     * @param  list<array{id: int, category: string, category_label: string, details: string|null, status: string, status_label: string, private_note: string|null, created_at: string, resolved_at: string|null}>  $reports
     * @param  array{id: int, type: string, reason: string, expires_at: string|null}|null  $activeRestriction
     */
    public function __construct(
        public ReviewItemData $review,
        public ?int $authorUserId,
        public ?string $moderatorNote,
        public int $openReportCount,
        public array $reports,
        public ?array $activeRestriction,
    ) {}
}
