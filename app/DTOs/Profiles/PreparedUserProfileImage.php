<?php

declare(strict_types=1);

namespace App\DTOs\Profiles;

final readonly class PreparedUserProfileImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
        public int $size,
        public int $width,
        public int $height,
    ) {}
}
