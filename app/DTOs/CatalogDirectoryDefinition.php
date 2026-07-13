<?php

namespace App\DTOs;

use App\Enums\CatalogFilterType;

final readonly class CatalogDirectoryDefinition
{
    public function __construct(
        public string $key,
        public string $path,
        public string $indexRouteName,
        public string $detailRouteName,
        public string $title,
        public string $description,
        public string $itemLabel,
        public string $icon,
        public ?CatalogFilterType $filterType,
        public bool $supportsAlphabet,
        public int $perPage,
    ) {}

    public function isYear(): bool
    {
        return $this->filterType === null;
    }
}
