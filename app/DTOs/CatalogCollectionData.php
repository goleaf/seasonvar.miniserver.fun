<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;

final readonly class CatalogCollectionData
{
    public function __construct(
        public string $name,
        public ?string $description,
        public CatalogCollectionVisibility $visibility,
        public CatalogCollectionSort $sortMode = CatalogCollectionSort::Manual,
        public CatalogCollectionType $type = CatalogCollectionType::User,
        public ?string $contentLocale = null,
        public ?string $publicId = null,
        public ?string $seoTitle = null,
        public ?string $seoDescription = null,
    ) {}
}
