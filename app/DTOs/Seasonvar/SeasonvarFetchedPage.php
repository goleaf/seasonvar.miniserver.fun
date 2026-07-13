<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

final readonly class SeasonvarFetchedPage
{
    public function __construct(
        public int $sourcePageId,
        public string $body,
        public string $contentHash,
        public int $httpStatus,
        public bool $contentChanged,
        public ?int $snapshotId,
        public bool $notModified = false,
    ) {}
}
