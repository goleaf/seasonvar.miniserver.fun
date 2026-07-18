<?php

declare(strict_types=1);

namespace App\Services\Premium;

use Illuminate\Support\Facades\Schema;

final class PremiumSchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= collect([
            'premium_plans',
            'premium_promotions',
            'premium_coupons',
            'premium_checkout_sessions',
            'premium_subscriptions',
            'premium_payments',
            'premium_refunds',
            'premium_disputes',
            'premium_coupon_redemptions',
            'premium_entitlements',
            'premium_provider_events',
            'premium_audit_events',
        ])->every(static fn (string $table): bool => Schema::hasTable($table));
    }
}
