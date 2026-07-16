<?php

declare(strict_types=1);

namespace App\DTOs;

use InvalidArgumentException;

final readonly class LicensedMediaFileSizeSourceData
{
    public function __construct(
        public int $mediaId,
        public ?int $catalogTitleId,
        public ?string $playbackUrl,
        public string $path,
        public ?string $format,
    ) {
        if ($mediaId < 1) {
            throw new InvalidArgumentException('A persisted licensed media ID is required.');
        }
    }
}
