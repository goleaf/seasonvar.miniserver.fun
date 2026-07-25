<?php

declare(strict_types=1);

namespace App\Services\Premium;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class PremiumSchema
{
    private const REQUIRED_TABLES = [
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
    ];

    private ?bool $ready = null;

    public function ready(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        try {
            $tables = Schema::getTableListing(schemaQualified: false);

            return $this->ready = array_diff(self::REQUIRED_TABLES, $tables) === [];
        } catch (Throwable) {
            return $this->ready = false;
        }
    }
}
