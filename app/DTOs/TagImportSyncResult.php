<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class TagImportSyncResult
{
    /**
     * @param  list<int>  $tagIds
     * @param  list<int>  $detachedTagIds
     */
    public function __construct(
        public array $tagIds,
        public array $detachedTagIds,
        public bool $publicMetadataChanged,
    ) {}
}
