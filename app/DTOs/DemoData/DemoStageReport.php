<?php

declare(strict_types=1);

namespace App\DTOs\DemoData;

final readonly class DemoStageReport
{
    /**
     * @param  array<string, int>  $counters
     */
    public function __construct(
        public string $stage,
        public array $counters,
        public float $elapsedSeconds,
    ) {}
}
