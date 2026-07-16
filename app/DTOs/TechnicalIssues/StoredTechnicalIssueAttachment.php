<?php

declare(strict_types=1);

namespace App\DTOs\TechnicalIssues;

final readonly class StoredTechnicalIssueAttachment
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $displayName,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public int $width,
        public int $height,
        public string $contentHash,
    ) {}
}
