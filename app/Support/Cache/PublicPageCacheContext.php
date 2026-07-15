<?php

declare(strict_types=1);

namespace App\Support\Cache;

final readonly class PublicPageCacheContext
{
    /** @param array<string, mixed> $dimensions */
    public function __construct(
        public CacheDomain $domain,
        public array $dimensions,
        public string $versionScope = 'public',
    ) {}
}
