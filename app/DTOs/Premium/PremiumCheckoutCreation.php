<?php

declare(strict_types=1);

namespace App\DTOs\Premium;

use App\Models\PremiumCheckoutSession;

final readonly class PremiumCheckoutCreation
{
    public function __construct(
        public PremiumCheckoutSession $checkout,
        public string $redirectUrl,
    ) {}
}
