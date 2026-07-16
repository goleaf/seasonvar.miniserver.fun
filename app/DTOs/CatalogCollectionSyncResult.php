<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogCollectionSyncStatus;

final readonly class CatalogCollectionSyncResult
{
    /**
     * @param  array<string, int>  $counters
     * @param  list<string>  $errors
     */
    public function __construct(
        public CatalogCollectionSyncStatus $status,
        public array $counters,
        public array $errors,
        public ?int $runId,
        public bool $dryRun,
    ) {}
}
