<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogCollectionClassificationResult
{
    /**
     * @param  list<int>  $changedCollectionIds
     */
    public function __construct(
        public int $changed,
        public int $skipped,
        public array $changedCollectionIds,
    ) {}
}
