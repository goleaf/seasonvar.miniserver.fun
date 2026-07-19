<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminFilterData
{
    /** @param array<string, string> $options */
    public function __construct(
        public string $code,
        public string $label,
        public string $type,
        public array $options = [],
    ) {}
}
