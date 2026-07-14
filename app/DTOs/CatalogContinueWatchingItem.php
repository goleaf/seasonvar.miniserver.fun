<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\CatalogTitle;
use App\Models\Episode;

final readonly class CatalogContinueWatchingItem
{
    public function __construct(
        public CatalogTitle $title,
        public Episode $episode,
        public string $actionType,
        public string $actionLabel,
        public int $positionSeconds,
        public ?int $progressPercent,
    ) {}
}
