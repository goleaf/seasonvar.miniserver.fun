<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminNavigationItemData
{
    public function __construct(
        public string $code,
        public string $group,
        public string $routeName,
        public string $url,
        public string $label,
        public string $icon,
        public bool $active,
    ) {}
}
