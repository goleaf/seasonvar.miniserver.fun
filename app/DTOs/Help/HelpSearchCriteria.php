<?php

declare(strict_types=1);

namespace App\DTOs\Help;

final readonly class HelpSearchCriteria
{
    public function __construct(
        public string $query,
        public string $locale,
        public ?string $categoryCode = null,
        public int $page = 1,
        public int $perPage = 12,
    ) {}
}
