<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PreparedImportedCollectionCover
{
    public function __construct(
        public string $bytes,
        public string $contentHash,
        public string $mimeType,
        public int $size,
        public int $width,
        public int $height,
    ) {}
}
