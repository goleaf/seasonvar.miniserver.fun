<?php

declare(strict_types=1);

namespace App\DTOs\Reviews;

final readonly class PublicReviewActivityData
{
    public function __construct(
        public int $id,
        public ?string $title,
        public ?string $excerpt,
        public bool $isSpoiler,
        public ?string $targetTitle,
        public ?string $targetUrl,
        public string $directUrl,
        public string $publishedAt,
    ) {}
}
