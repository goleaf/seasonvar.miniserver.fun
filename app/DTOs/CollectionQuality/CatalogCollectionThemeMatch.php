<?php

declare(strict_types=1);

namespace App\DTOs\CollectionQuality;

use App\Enums\CatalogCollectionInclusionReason;

final readonly class CatalogCollectionThemeMatch
{
    public function __construct(
        public int $percent,
        public CatalogCollectionInclusionReason $reason,
    ) {}
}
