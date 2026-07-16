<?php

declare(strict_types=1);

namespace App\DTOs\ContentRequests;

use App\Enums\ContentRequestDuplicateConfidence;

final readonly class ContentRequestDuplicateResult
{
    /** @param list<array{public_id: string|null, title: string|null, status: string, url: string|null}> $candidates */
    public function __construct(
        public ContentRequestDuplicateConfidence $confidence,
        public ?string $exactIdentityHash,
        public array $candidates = [],
    ) {}
}
