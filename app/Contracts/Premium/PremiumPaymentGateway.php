<?php

declare(strict_types=1);

namespace App\Contracts\Premium;

use App\DTOs\Premium\PremiumHostedCheckout;
use App\DTOs\Premium\PremiumProviderEventData;
use App\Models\PremiumCheckoutSession;

interface PremiumPaymentGateway
{
    public function code(): string;

    public function environment(): string;

    public function supports(string $capability): bool;

    public function createHostedCheckout(PremiumCheckoutSession $checkout, string $successUrl, string $cancelUrl): PremiumHostedCheckout;

    /**
     * Implementations must verify the provider signature against the unmodified raw body before returning data.
     *
     * @param  array<string, list<string|null>>  $headers
     */
    public function verifyAndParseWebhook(string $rawBody, array $headers): PremiumProviderEventData;
}
