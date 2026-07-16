<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogCollectionSourceMatchStatus;

final readonly class CatalogCollectionSourceMatch
{
    /** @param array<string, int|string> $reasons */
    public function __construct(
        public CatalogCollectionSourceMatchStatus $status,
        public ?int $catalogTitleId,
        public ?string $method,
        public int $confidence,
        public array $reasons,
    ) {}
}
