<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminPermissionData
{
    public function __construct(
        public string $code,
        public string $label,
        public string $sensitivity,
        public string $sensitivityLabel,
    ) {}
}
