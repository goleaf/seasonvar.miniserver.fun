<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogCacheWarmWork
{
    /** @param list<int> $titleIds */
    public function __construct(
        public int $generation,
        public bool $refresh,
        public array $titleIds,
    ) {}
}
