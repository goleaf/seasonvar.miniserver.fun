<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Tag;

final readonly class ResolvedTag
{
    public function __construct(
        public Tag $tag,
        public string $matchType,
        public string $requestedValue,
    ) {}

    public function isCanonical(): bool
    {
        return $this->matchType === 'canonical'
            && hash_equals((string) $this->tag->slug, $this->requestedValue);
    }
}
