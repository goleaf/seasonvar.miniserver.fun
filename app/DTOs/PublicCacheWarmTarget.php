<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PublicCacheWarmTarget
{
    public function __construct(
        public string $relativeUrl,
        public string $accept = 'text/html',
        public string $kind = 'page',
    ) {}
}
