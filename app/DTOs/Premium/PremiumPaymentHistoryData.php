<?php

declare(strict_types=1);

namespace App\DTOs\Premium;

final readonly class PremiumPaymentHistoryData
{
    public function __construct(
        public string $publicId,
        public ?string $planCode,
        public string $status,
        public string $amount,
        public ?string $createdAt,
        public ?string $confirmedAt,
        public ?string $refundedAmount,
    ) {}
}
