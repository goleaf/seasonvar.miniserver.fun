<?php

declare(strict_types=1);

namespace App\DTOs\Profiles;

final readonly class PublicProfileWatchItemData
{
    public function __construct(
        public ?string $title,
        public ?string $originalTitle,
        public ?int $year,
        public ?string $url,
        public ?string $posterUrl,
    ) {}
}
