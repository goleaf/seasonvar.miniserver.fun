<?php

declare(strict_types=1);

namespace App\DTOs\DemoData;

final readonly class DemoAuditReport
{
    /**
     * @param  array<string, int>  $counters
     * @param  list<string>  $violations
     */
    public function __construct(
        public array $counters,
        public array $violations,
    ) {}

    public function passed(): bool
    {
        return $this->violations === [];
    }
}
