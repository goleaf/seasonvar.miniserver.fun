<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminDashboardMetricData
{
    public function __construct(
        public string $code,
        public string $label,
        public int $value,
        public string $formattedValue,
    ) {}
}
