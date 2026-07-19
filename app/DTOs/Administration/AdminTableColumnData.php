<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminTableColumnData
{
    public function __construct(
        public string $code,
        public string $label,
        public ?string $sortCode = null,
        public string $alignment = 'start',
        public bool $mobilePriority = false,
    ) {}
}
