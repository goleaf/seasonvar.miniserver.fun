<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogPrimaryAction
{
    public function __construct(
        public string $type,
        public string $label,
        public ?int $seasonId = null,
        public ?int $episodeId = null,
        public ?int $mediaId = null,
        public int $positionSeconds = 0,
    ) {}

    public function isPlayable(): bool
    {
        return $this->mediaId !== null;
    }
}
