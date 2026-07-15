<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class UserLibraryFilters
{
    public function __construct(
        public string $query = '',
        public ?string $type = null,
        public ?int $year = null,
        public ?string $personalTagPublicId = null,
        public string $sort = 'updated',
        public string $direction = 'desc',
        public int $perPage = 20,
    ) {}
}
