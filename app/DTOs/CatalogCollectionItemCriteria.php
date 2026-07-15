<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogCollectionSort;

final readonly class CatalogCollectionItemCriteria
{
    public function __construct(
        public string $search = '',
        public ?string $genre = null,
        public ?string $country = null,
        public ?string $status = null,
        public ?int $year = null,
        public CatalogCollectionSort $sort = CatalogCollectionSort::Manual,
        public int $perPage = 24,
    ) {}
}
