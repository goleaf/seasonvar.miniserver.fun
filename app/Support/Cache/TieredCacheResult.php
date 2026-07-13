<?php

declare(strict_types=1);

namespace App\Support\Cache;

final readonly class TieredCacheResult
{
    public function __construct(
        public mixed $value,
        public string $source,
        public bool $stale = false,
        public bool $negative = false,
    ) {}
}
