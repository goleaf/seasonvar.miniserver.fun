<?php

declare(strict_types=1);

namespace App\DTOs\Premium;

use Carbon\CarbonImmutable;

final readonly class PremiumProviderEventData
{
    public function __construct(
        public string $eventId,
        public string $type,
        public string $environment,
        public CarbonImmutable $occurredAt,
        public string $objectType,
        public string $objectId,
        public ?string $checkoutPublicId = null,
        public ?string $customerId = null,
        public ?string $paymentId = null,
        public ?string $subscriptionId = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $status = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
        public bool $cancelAtPeriodEnd = false,
        public ?CarbonImmutable $graceEndsAt = null,
        public ?string $refundId = null,
        public ?string $disputeId = null,
    ) {}
}
