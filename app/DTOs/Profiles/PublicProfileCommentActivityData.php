<?php

declare(strict_types=1);

namespace App\DTOs\Profiles;

final readonly class PublicProfileCommentActivityData
{
    public function __construct(
        public int $id,
        public ?string $excerpt,
        public bool $isSpoiler,
        public ?string $targetTitle,
        public ?string $targetUrl,
        public string $directUrl,
        public string $publishedAt,
    ) {}
}
