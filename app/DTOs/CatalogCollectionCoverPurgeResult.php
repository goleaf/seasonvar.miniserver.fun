<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogCollectionCoverPurgeResult
{
    public function __construct(
        public bool $executed,
        public int $files,
        public int $bytes,
        public int $collectionRows,
        public int $sourceRows,
        public int $failures,
        public bool $readyForSchemaDrop,
    ) {}
}
