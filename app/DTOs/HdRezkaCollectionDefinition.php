<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class HdRezkaCollectionDefinition
{
    public function __construct(
        public string $sourceKey,
        public string $name,
        public string $path,
        public ?string $coverPath,
        public int $position,
    ) {}
}
