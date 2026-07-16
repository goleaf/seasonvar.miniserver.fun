<?php

declare(strict_types=1);

namespace App\DTOs\ContentRequests;

use App\Enums\ContentRequestDuplicateConfidence;

final readonly class ContentRequestDuplicateResult
{
    /** @param list<array{public_id: string, title: string, status: string, url: string}> $candidates */
    public function __construct(
        public ContentRequestDuplicateConfidence $confidence,
        public ?string $exactIdentityHash,
        public array $candidates = [],
    ) {}
}
