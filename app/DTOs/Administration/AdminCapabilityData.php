<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminCapabilityData
{
    public function __construct(
        public string $code,
        public string $label,
        public string $description,
        public bool $installed,
        public string $statusLabel,
    ) {}
}
