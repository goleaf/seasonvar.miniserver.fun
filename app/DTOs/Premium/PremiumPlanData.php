<?php

declare(strict_types=1);

namespace App\DTOs\Premium;

final readonly class PremiumPlanData
{
    /** @param list<array{code: string, label: string, description: string}> $features */
    public function __construct(
        public string $code,
        public string $name,
        public string $description,
        public string $type,
        public ?string $price,
        public ?int $durationDays,
        public ?string $billingInterval,
        public bool $recurring,
        public bool $providerAvailable,
        public array $features,
    ) {}
}
