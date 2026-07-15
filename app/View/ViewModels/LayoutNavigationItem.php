<?php

declare(strict_types=1);

namespace App\View\ViewModels;

final readonly class LayoutNavigationItem
{
    public function __construct(
        public string $url,
        public string $icon,
        public string $label,
        public string $className,
        public ?string $ariaCurrent,
    ) {}
}
