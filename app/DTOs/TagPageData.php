<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class TagPageData
{
    /**
     * @param  list<string>  $aliases
     * @param  list<array{public_id: string, name: string, slug: string, count: int}>  $related
     */
    public function __construct(
        public string $publicId,
        public string $name,
        public string $slug,
        public string $type,
        public ?string $shortDescription,
        public ?string $description,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public array $aliases,
        public array $related,
        public int $publicTitleCount,
    ) {}
}
