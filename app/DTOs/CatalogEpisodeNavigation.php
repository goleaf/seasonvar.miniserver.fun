<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Episode;

final readonly class CatalogEpisodeNavigation
{
    public function __construct(
        public ?Episode $previous = null,
        public ?Episode $next = null,
    ) {}
}
