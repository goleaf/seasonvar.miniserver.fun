<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PublicCacheWarmBatch
{
    /**
     * @param  list<PublicCacheWarmTarget>  $targets
     * @param  array{source: string, position: array<string, int|string>}|null  $nextCursor
     */
    public function __construct(
        public array $targets,
        public ?array $nextCursor,
        public bool $completed,
    ) {}
}
