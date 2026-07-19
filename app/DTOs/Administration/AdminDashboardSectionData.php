<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminDashboardSectionData
{
    /**
     * @param  list<AdminDashboardMetricData>  $metrics
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $description,
        public string $icon,
        public array $metrics,
        public bool $available,
        public string $readAtIso,
        public string $readAtLabel,
    ) {}
}
