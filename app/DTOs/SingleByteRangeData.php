<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SingleByteRangeData
{
    public function __construct(
        public string $header,
        public ?int $start,
        public ?int $end,
        public ?int $suffixLength = null,
    ) {}
}
