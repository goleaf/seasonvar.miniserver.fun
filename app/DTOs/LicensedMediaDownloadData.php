<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LicensedMediaDownloadData
{
    public function __construct(
        public bool $eligible,
        public string $reasonKey,
        public ?VerifiedExternalUrlData $target = null,
        public ?string $extension = null,
        public ?string $contentType = null,
    ) {}

    public static function unavailable(string $reasonKey): self
    {
        return new self(false, $reasonKey);
    }

    public static function available(
        VerifiedExternalUrlData $target,
        string $extension,
        string $contentType,
    ): self {
        return new self(true, 'catalog.download.available', $target, $extension, $contentType);
    }
}
