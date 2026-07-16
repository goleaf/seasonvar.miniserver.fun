<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonInterface;

final readonly class MobileTokenRotationResult
{
    public function __construct(
        public string $token,
        public CarbonInterface $expiresAt,
    ) {}
}
