<?php

declare(strict_types=1);

namespace App\DTOs\Premium;

use Carbon\CarbonImmutable;

final readonly class PremiumHostedCheckout
{
    public function __construct(
        public string $providerSessionId,
        public string $redirectUrl,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
