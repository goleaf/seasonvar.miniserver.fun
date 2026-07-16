<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class HdRezkaCollectionItemData
{
    /** @param list<string> $countries */
    public function __construct(
        public string $sourceItemKey,
        public string $title,
        public string $normalizedTitleKey,
        public ?int $year,
        public ?string $type,
        public array $countries,
        public string $detailPath,
        public int $page,
        public int $position,
    ) {}
}
