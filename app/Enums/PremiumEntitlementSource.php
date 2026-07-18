<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumEntitlementSource: string
{
    case Subscription = 'subscription';
    case OneTimePurchase = 'one_time_purchase';
    case LifetimePurchase = 'lifetime_purchase';
    case ManualGrant = 'manual_grant';
    case Promotion = 'promotion';
    case AccountMigration = 'account_migration';
    case SupportCompensation = 'support_compensation';

    public function label(): string
    {
        return __("premium.sources.{$this->value}");
    }

    public function isAdministrative(): bool
    {
        return in_array($this, [self::ManualGrant, self::SupportCompensation, self::AccountMigration], true);
    }
}
